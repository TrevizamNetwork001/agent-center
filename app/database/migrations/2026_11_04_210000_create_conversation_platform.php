<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE conversation_settings(tenant_id bigint PRIMARY KEY REFERENCES tenants(id),enabled boolean NOT NULL DEFAULT false,retention_days integer NOT NULL DEFAULT 30 CHECK(retention_days BETWEEN 7 AND 365),updated_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL);
CREATE TABLE conversations(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),user_id bigint NOT NULL REFERENCES users(id),channel varchar(20) NOT NULL CHECK(channel='web'),status varchar(20) NOT NULL CHECK(status IN('OPEN','CANCELLED','CLOSED')),state varchar(40) NOT NULL CHECK(state IN('NEW','ACTIVE','WAITING_FOR_TARGET','WAITING_FOR_CONFIRMATION','DIAGNOSTIC_RUNNING','DIAGNOSTIC_COMPLETED','FOLLOW_UP','CANCELLED','CLOSED')),current_profile_id uuid,current_diagnostic_id uuid,current_target varchar(512),last_intent varchar(40),context_json jsonb NOT NULL DEFAULT '{}',creation_key uuid NOT NULL,sequence bigint NOT NULL DEFAULT 0,started_at timestamptz NOT NULL,last_activity_at timestamptz NOT NULL,closed_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),UNIQUE(tenant_id,user_id,creation_key),FOREIGN KEY(current_profile_id,tenant_id) REFERENCES profiles(id,tenant_id),FOREIGN KEY(current_diagnostic_id,tenant_id) REFERENCES diagnostics(id,tenant_id));
CREATE TABLE conversation_messages(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,conversation_id uuid NOT NULL,request_user_id bigint REFERENCES users(id),request_key uuid,sender_type varchar(16) NOT NULL CHECK(sender_type IN('user','system')),message_type varchar(24) NOT NULL CHECK(message_type IN('text','result','interpretation')),body text NOT NULL CHECK(octet_length(body)<=24000),detected_intent varchar(40),metadata jsonb NOT NULL DEFAULT '{}',sequence bigint NOT NULL,event_key varchar(100),created_at timestamptz NOT NULL,FOREIGN KEY(conversation_id,tenant_id) REFERENCES conversations(id,tenant_id),UNIQUE(conversation_id,sequence),UNIQUE(tenant_id,request_user_id,request_key),UNIQUE(conversation_id,event_key));
CREATE TABLE conversation_events(id bigserial PRIMARY KEY,tenant_id bigint NOT NULL,conversation_id uuid NOT NULL,event_type varchar(40) NOT NULL,previous_state varchar(40) NOT NULL,next_state varchar(40) NOT NULL,metadata jsonb NOT NULL DEFAULT '{}',created_at timestamptz NOT NULL,FOREIGN KEY(conversation_id,tenant_id) REFERENCES conversations(id,tenant_id));
CREATE INDEX conversation_history ON conversations(tenant_id,user_id,last_activity_at DESC,id);
CREATE INDEX conversation_running ON conversations(tenant_id,state,current_diagnostic_id);
CREATE INDEX conversation_message_order ON conversation_messages(tenant_id,conversation_id,sequence);
CREATE INDEX conversation_event_order ON conversation_events(tenant_id,conversation_id,id);
SQL);
        foreach (['conversation_settings', 'conversations', 'conversation_messages', 'conversation_events'] as $t) {
            DB::statement("ALTER TABLE $t ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $t FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $t USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        }
        // Payload history cannot be edited. Retention is a privileged, documented purge.
        foreach (['conversation_messages', 'conversation_events'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_immutable BEFORE UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation()");
        }
    }

    public function down(): void
    {
        foreach (['conversation_events', 'conversation_messages', 'conversations', 'conversation_settings'] as $t) {
            DB::statement("DROP TABLE $t");
        }
    }
};
