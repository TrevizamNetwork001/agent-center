<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE telegram_bots ADD COLUMN restrict_members boolean NOT NULL DEFAULT false;
CREATE TABLE telegram_groups(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,bot_id uuid NOT NULL,bot_generation uuid NOT NULL,generation uuid NOT NULL,requested_by bigint NOT NULL REFERENCES users(id),requested_link_id uuid NOT NULL,chat_id bigint,chat_title varchar(150),status varchar(30) NOT NULL CHECK(status IN('CODE','AWAITING_APPROVAL','ACTIVE','REVOKED')),code_hash char(64),expires_at timestamptz NOT NULL,reply_mode varchar(12) NOT NULL CHECK(reply_mode IN('private','group')),shared_ack boolean NOT NULL DEFAULT false,verified_at timestamptz,revoked_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),FOREIGN KEY(bot_id,tenant_id) REFERENCES telegram_bots(id,tenant_id),FOREIGN KEY(requested_link_id,tenant_id) REFERENCES telegram_links(id,tenant_id),CHECK(reply_mode<>'group' OR shared_ack),CHECK(chat_id IS NULL OR chat_id<0));
CREATE UNIQUE INDEX telegram_company_chat ON telegram_groups(chat_id) WHERE status IN('AWAITING_APPROVAL','ACTIVE');
CREATE TABLE telegram_members(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,bot_id uuid NOT NULL,link_id uuid NOT NULL,status varchar(12) NOT NULL CHECK(status IN('ACTIVE','REVOKED')),approved_by bigint NOT NULL REFERENCES users(id),approved_at timestamptz NOT NULL,revoked_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),UNIQUE(bot_id,link_id),FOREIGN KEY(bot_id,tenant_id) REFERENCES telegram_bots(id,tenant_id),FOREIGN KEY(link_id,tenant_id) REFERENCES telegram_links(id,tenant_id));
CREATE TABLE telegram_group_contexts(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,group_id uuid NOT NULL,link_id uuid NOT NULL,generation uuid NOT NULL,thread_id bigint NOT NULL DEFAULT 0 CHECK(thread_id>=0),conversation_id uuid,cursor bigint NOT NULL DEFAULT 0,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),UNIQUE(group_id,link_id,thread_id),FOREIGN KEY(group_id,tenant_id) REFERENCES telegram_groups(id,tenant_id),FOREIGN KEY(link_id,tenant_id) REFERENCES telegram_links(id,tenant_id),FOREIGN KEY(conversation_id,tenant_id) REFERENCES conversations(id,tenant_id));
ALTER TABLE conversations ADD COLUMN telegram_context_id uuid;
ALTER TABLE conversations ADD CONSTRAINT conversations_telegram_context_fk FOREIGN KEY(telegram_context_id,tenant_id) REFERENCES telegram_group_contexts(id,tenant_id);
ALTER TABLE telegram_updates ADD COLUMN chat_type varchar(12) NOT NULL DEFAULT 'private',ADD COLUMN chat_title varchar(150),ADD COLUMN group_id uuid,ADD COLUMN group_generation uuid,ADD COLUMN thread_id bigint NOT NULL DEFAULT 0;
ALTER TABLE telegram_updates ADD CONSTRAINT telegram_update_group_fk FOREIGN KEY(group_id,tenant_id) REFERENCES telegram_groups(id,tenant_id);
ALTER TABLE telegram_outbox ADD COLUMN group_id uuid,ADD COLUMN group_generation uuid,ADD COLUMN context_id uuid,ADD COLUMN thread_id bigint NOT NULL DEFAULT 0;
ALTER TABLE telegram_outbox ADD CONSTRAINT telegram_outbox_group_fk FOREIGN KEY(group_id,tenant_id) REFERENCES telegram_groups(id,tenant_id);
ALTER TABLE telegram_outbox ADD CONSTRAINT telegram_outbox_context_fk FOREIGN KEY(context_id,tenant_id) REFERENCES telegram_group_contexts(id,tenant_id);
SQL);
        foreach (['telegram_groups', 'telegram_members', 'telegram_group_contexts'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $table USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        }
    }

    public function down(): void
    {
        foreach (['telegram_groups', 'telegram_members', 'telegram_group_contexts'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT telegram_companies_no_history CHECK(false)");
        }
        DB::statement('ALTER TABLE conversations ADD CONSTRAINT telegram_companies_rollback_empty CHECK(telegram_context_id IS NULL)');
        DB::unprepared(<<<'SQL'
ALTER TABLE telegram_outbox DROP CONSTRAINT telegram_outbox_context_fk,DROP CONSTRAINT telegram_outbox_group_fk;
ALTER TABLE telegram_outbox DROP COLUMN group_id,DROP COLUMN group_generation,DROP COLUMN context_id,DROP COLUMN thread_id;
ALTER TABLE telegram_updates DROP CONSTRAINT telegram_update_group_fk;
ALTER TABLE telegram_updates DROP COLUMN chat_type,DROP COLUMN chat_title,DROP COLUMN group_id,DROP COLUMN group_generation,DROP COLUMN thread_id;
ALTER TABLE conversations DROP CONSTRAINT telegram_companies_rollback_empty,DROP CONSTRAINT conversations_telegram_context_fk,DROP COLUMN telegram_context_id;
DROP TABLE telegram_group_contexts;
DROP TABLE telegram_members;
DROP TABLE telegram_groups;
ALTER TABLE telegram_bots DROP COLUMN restrict_members;
SQL);
    }
};
