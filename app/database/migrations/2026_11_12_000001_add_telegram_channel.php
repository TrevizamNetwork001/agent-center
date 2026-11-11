<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE conversations DROP CONSTRAINT conversations_channel_check;
ALTER TABLE conversations ADD CONSTRAINT conversations_channel_check CHECK(channel IN ('web','telegram'));
ALTER TABLE conversations ADD COLUMN contact_id uuid;
CREATE TABLE telegram_bots(id uuid PRIMARY KEY,tenant_id bigint NOT NULL UNIQUE REFERENCES tenants(id),token_cipher text NOT NULL,token_fingerprint char(64) NOT NULL UNIQUE,secret_cipher text NOT NULL,username varchar(64) NOT NULL,telegram_bot_id bigint NOT NULL UNIQUE,enabled boolean NOT NULL DEFAULT false,generation uuid NOT NULL,webhook_status varchar(30) NOT NULL DEFAULT 'NOT_REGISTERED',last_error varchar(40),last_checked_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id));
CREATE TABLE telegram_links(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,user_id bigint NOT NULL REFERENCES users(id),bot_id uuid NOT NULL,telegram_user_id bigint,chat_id bigint,status varchar(30) NOT NULL CHECK(status IN('CODE','AWAITING_APPROVAL','ACTIVE','REVOKED')),code_hash char(64),expires_at timestamptz NOT NULL,verified_at timestamptz,revoked_at timestamptz,conversation_id uuid,cursor bigint NOT NULL DEFAULT 0,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),FOREIGN KEY(bot_id,tenant_id) REFERENCES telegram_bots(id,tenant_id),FOREIGN KEY(conversation_id,tenant_id) REFERENCES conversations(id,tenant_id));
CREATE UNIQUE INDEX telegram_user_active ON telegram_links(bot_id,user_id) WHERE status IN ('CODE','AWAITING_APPROVAL','ACTIVE');
CREATE UNIQUE INDEX telegram_sender_active ON telegram_links(bot_id,telegram_user_id) WHERE status IN ('AWAITING_APPROVAL','ACTIVE');
CREATE TABLE telegram_updates(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,bot_id uuid NOT NULL,generation uuid NOT NULL,update_id bigint NOT NULL,chat_id bigint NOT NULL,message_id bigint NOT NULL,sender_id bigint NOT NULL,message_date timestamptz NOT NULL,payload_cipher text,status varchar(24) NOT NULL DEFAULT 'PENDING',digest char(64) NOT NULL,attempts integer NOT NULL DEFAULT 0,next_attempt_at timestamptz NOT NULL,last_error varchar(40),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(bot_id,tenant_id) REFERENCES telegram_bots(id,tenant_id),UNIQUE(bot_id,generation,update_id),UNIQUE(bot_id,generation,chat_id,message_id));
CREATE TABLE telegram_outbox(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,bot_id uuid NOT NULL,generation uuid NOT NULL,link_id uuid,conversation_id uuid,message_id uuid,chat_id bigint NOT NULL,body_cipher text,status varchar(24) NOT NULL DEFAULT 'PENDING',attempts integer NOT NULL DEFAULT 0,next_attempt_at timestamptz NOT NULL,last_error varchar(40),telegram_message_id bigint,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(bot_id,tenant_id) REFERENCES telegram_bots(id,tenant_id),FOREIGN KEY(link_id,tenant_id) REFERENCES telegram_links(id,tenant_id),FOREIGN KEY(conversation_id,tenant_id) REFERENCES conversations(id,tenant_id),FOREIGN KEY(message_id) REFERENCES conversation_messages(id),UNIQUE(link_id,message_id));
CREATE INDEX telegram_updates_pending ON telegram_updates(tenant_id,status,next_attempt_at);
CREATE INDEX telegram_outbox_pending ON telegram_outbox(tenant_id,status,next_attempt_at);
SQL);
        foreach (['telegram_bots', 'telegram_links', 'telegram_updates', 'telegram_outbox'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $table USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        }
    }

    public function down(): void
    {
        // Constraint validation inspects all rows, even when FORCE RLS hides history.
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT telegram_rollback_empty CHECK(channel='web')");
        foreach (['telegram_outbox', 'telegram_updates', 'telegram_links', 'telegram_bots'] as $table) {
            DB::statement("DROP TABLE $table");
        }
        DB::statement('ALTER TABLE conversations DROP COLUMN contact_id');
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT conversations_channel_check');
        DB::statement('ALTER TABLE conversations RENAME CONSTRAINT telegram_rollback_empty TO conversations_channel_check');
    }
};
