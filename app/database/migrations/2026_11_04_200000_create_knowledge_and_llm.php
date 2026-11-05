<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE knowledge_items(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),key varchar(80) NOT NULL,title varchar(160) NOT NULL,category varchar(60) NOT NULL,scope varchar(20) NOT NULL CHECK(scope IN('global','diagnostic','profile','operations','tenant')),profile_id uuid, tags jsonb NOT NULL DEFAULT '[]',status varchar(20) NOT NULL CHECK(status IN('DRAFT','PUBLISHED','ARCHIVED')),current_version_id uuid,draft_version_id uuid,created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(tenant_id,key),UNIQUE(id,tenant_id),FOREIGN KEY(profile_id,tenant_id) REFERENCES profiles(id,tenant_id),CHECK((scope='profile')=(profile_id IS NOT NULL)));
CREATE TABLE prompts(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),key varchar(80) NOT NULL,name varchar(160) NOT NULL,purpose varchar(40) NOT NULL CHECK(purpose IN('diagnostic_summary','diagnostic_explanation','knowledge_assistance')),status varchar(20) NOT NULL CHECK(status IN('DRAFT','PUBLISHED','ARCHIVED')),current_version_id uuid,draft_version_id uuid,created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(tenant_id,key),UNIQUE(id,tenant_id));
CREATE TABLE knowledge_version_contents(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,knowledge_item_id uuid NOT NULL,version_number integer NOT NULL CHECK(version_number>0),content text NOT NULL CHECK(octet_length(content)<=48000),change_summary varchar(300),created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,UNIQUE(knowledge_item_id,version_number),UNIQUE(id,tenant_id),UNIQUE(id,knowledge_item_id,tenant_id),FOREIGN KEY(knowledge_item_id,tenant_id) REFERENCES knowledge_items(id,tenant_id));
CREATE TABLE prompt_version_contents(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,prompt_id uuid NOT NULL,version_number integer NOT NULL CHECK(version_number>0),system_template text NOT NULL CHECK(octet_length(system_template)<=24000),user_template text CHECK(octet_length(user_template)<=24000),expected_output_schema jsonb NOT NULL,change_summary varchar(300),created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,UNIQUE(prompt_id,version_number),UNIQUE(id,tenant_id),UNIQUE(id,prompt_id,tenant_id),FOREIGN KEY(prompt_id,tenant_id) REFERENCES prompts(id,tenant_id));
ALTER TABLE knowledge_items ADD FOREIGN KEY(current_version_id,id,tenant_id) REFERENCES knowledge_version_contents(id,knowledge_item_id,tenant_id),ADD FOREIGN KEY(draft_version_id,id,tenant_id) REFERENCES knowledge_version_contents(id,knowledge_item_id,tenant_id);
ALTER TABLE prompts ADD FOREIGN KEY(current_version_id,id,tenant_id) REFERENCES prompt_version_contents(id,prompt_id,tenant_id),ADD FOREIGN KEY(draft_version_id,id,tenant_id) REFERENCES prompt_version_contents(id,prompt_id,tenant_id);
CREATE TABLE version_events(id bigserial PRIMARY KEY,tenant_id bigint NOT NULL,knowledge_version_id uuid,prompt_version_id uuid,status varchar(20) NOT NULL CHECK(status IN('DRAFT','PUBLISHED','SUPERSEDED','ARCHIVED')),created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,CHECK(num_nonnulls(knowledge_version_id,prompt_version_id)=1),FOREIGN KEY(knowledge_version_id,tenant_id) REFERENCES knowledge_version_contents(id,tenant_id),FOREIGN KEY(prompt_version_id,tenant_id) REFERENCES prompt_version_contents(id,tenant_id));
CREATE INDEX knowledge_version_lifecycle ON version_events(tenant_id,knowledge_version_id,id DESC);
CREATE INDEX prompt_version_lifecycle ON version_events(tenant_id,prompt_version_id,id DESC);
CREATE VIEW knowledge_versions WITH(security_invoker=true) AS SELECT v.*,COALESCE((SELECT e.status FROM version_events e WHERE e.knowledge_version_id=v.id AND e.tenant_id=v.tenant_id ORDER BY e.id DESC LIMIT 1),'DRAFT') AS status FROM knowledge_version_contents v;
CREATE VIEW prompt_versions WITH(security_invoker=true) AS SELECT v.*,COALESCE((SELECT e.status FROM version_events e WHERE e.prompt_version_id=v.id AND e.tenant_id=v.tenant_id ORDER BY e.id DESC LIMIT 1),'DRAFT') AS status FROM prompt_version_contents v;
CREATE INDEX knowledge_selection ON knowledge_items(tenant_id,status,scope,profile_id);
CREATE INDEX knowledge_tags ON knowledge_items USING gin(tags);
CREATE TABLE llm_providers(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),name varchar(120) NOT NULL,provider_type varchar(40) NOT NULL CHECK(provider_type='openai_compatible'),base_url varchar(512) NOT NULL,enabled boolean NOT NULL DEFAULT false,timeout integer NOT NULL CHECK(timeout BETWEEN 1 AND 15),config jsonb NOT NULL DEFAULT '{}',secret_ciphertext text,secret_key_id varchar(16),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),CHECK((secret_ciphertext IS NULL)=(secret_key_id IS NULL)));
CREATE TABLE llm_models(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,provider_id uuid NOT NULL,name varchar(120) NOT NULL,model_identifier varchar(120) NOT NULL,enabled boolean NOT NULL DEFAULT false,context_limit integer CHECK(context_limit BETWEEN 1024 AND 2000000),config jsonb NOT NULL DEFAULT '{}',created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(id,tenant_id),UNIQUE(id,provider_id,tenant_id),FOREIGN KEY(provider_id,tenant_id) REFERENCES llm_providers(id,tenant_id));
CREATE TABLE llm_bindings(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),purpose varchar(40) NOT NULL CHECK(purpose IN('diagnostic_summary','diagnostic_explanation','knowledge_assistance')),provider_id uuid NOT NULL,model_id uuid NOT NULL,prompt_id uuid NOT NULL,enabled boolean NOT NULL DEFAULT false,config jsonb NOT NULL DEFAULT '{}',created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(tenant_id,purpose),FOREIGN KEY(model_id,provider_id,tenant_id) REFERENCES llm_models(id,provider_id,tenant_id),FOREIGN KEY(prompt_id,tenant_id) REFERENCES prompts(id,tenant_id));
CREATE TABLE diagnostic_interpretations(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,diagnostic_id uuid NOT NULL,classification varchar(30) NOT NULL,status varchar(20) NOT NULL CHECK(status IN('ASSISTED','FALLBACK')),reason varchar(40) NOT NULL,summary text NOT NULL,explanation text NOT NULL,next_steps jsonb NOT NULL,prompt_version_id uuid,provider_id uuid,model_id uuid,provider_snapshot jsonb,input_digest char(64),created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,UNIQUE(id,tenant_id),FOREIGN KEY(diagnostic_id,tenant_id) REFERENCES diagnostics(id,tenant_id),FOREIGN KEY(prompt_version_id,tenant_id) REFERENCES prompt_version_contents(id,tenant_id),FOREIGN KEY(provider_id,tenant_id) REFERENCES llm_providers(id,tenant_id),FOREIGN KEY(model_id,tenant_id) REFERENCES llm_models(id,tenant_id));
CREATE INDEX interpretation_recent ON diagnostic_interpretations(tenant_id,diagnostic_id,created_at DESC);
CREATE TABLE interpretation_sources(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,interpretation_id uuid NOT NULL,knowledge_item_id uuid NOT NULL,knowledge_version_id uuid NOT NULL,title varchar(160) NOT NULL,version_number integer NOT NULL,created_at timestamptz NOT NULL,FOREIGN KEY(interpretation_id,tenant_id) REFERENCES diagnostic_interpretations(id,tenant_id),FOREIGN KEY(knowledge_version_id,knowledge_item_id,tenant_id) REFERENCES knowledge_version_contents(id,knowledge_item_id,tenant_id),UNIQUE(interpretation_id,knowledge_version_id));
SQL);
        foreach (['knowledge_items', 'prompts', 'knowledge_version_contents', 'prompt_version_contents', 'version_events', 'llm_providers', 'llm_models', 'llm_bindings', 'diagnostic_interpretations', 'interpretation_sources'] as $t) {
            DB::statement("ALTER TABLE $t ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $t FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $t USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        }
        foreach (['knowledge_version_contents', 'prompt_version_contents', 'version_events', 'diagnostic_interpretations', 'interpretation_sources'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON $t FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation()");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW knowledge_versions,prompt_versions');
        DB::statement('ALTER TABLE knowledge_items DROP CONSTRAINT knowledge_items_current_version_id_id_tenant_id_fkey,DROP CONSTRAINT knowledge_items_draft_version_id_id_tenant_id_fkey');
        DB::statement('ALTER TABLE prompts DROP CONSTRAINT prompts_current_version_id_id_tenant_id_fkey,DROP CONSTRAINT prompts_draft_version_id_id_tenant_id_fkey');
        foreach (['interpretation_sources', 'diagnostic_interpretations', 'llm_bindings', 'llm_models', 'llm_providers', 'version_events', 'prompt_version_contents', 'knowledge_version_contents', 'prompts', 'knowledge_items'] as $t) {
            DB::statement("DROP TABLE $t");
        }
    }
};
