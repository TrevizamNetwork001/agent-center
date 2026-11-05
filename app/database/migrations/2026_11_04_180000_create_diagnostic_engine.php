<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE agents ADD CONSTRAINT agents_id_tenant_unique UNIQUE(id,tenant_id);
CREATE TABLE skills(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),key varchar(40) NOT NULL,display_name varchar(80) NOT NULL,capability varchar(60) NOT NULL,version integer NOT NULL DEFAULT 1,enabled boolean NOT NULL DEFAULT true,timeout integer NOT NULL CHECK(timeout BETWEEN 1 AND 20),safe boolean NOT NULL DEFAULT true CHECK(safe),input_schema jsonb NOT NULL,output_schema jsonb NOT NULL,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(tenant_id,key),UNIQUE(id,tenant_id),CHECK(capability IN ('agent_info','dns_lookup:v1','tcp_connect:v1','http_probe:v1','icmp_ping:v1','traceroute:v1')));
CREATE TABLE profiles(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),key varchar(40) NOT NULL,display_name varchar(80) NOT NULL,description text NOT NULL,enabled boolean NOT NULL DEFAULT true,version integer NOT NULL DEFAULT 1,definition jsonb NOT NULL,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,UNIQUE(tenant_id,key),UNIQUE(id,tenant_id));
CREATE TABLE diagnostics(id uuid PRIMARY KEY,tenant_id bigint NOT NULL REFERENCES tenants(id),agent_id uuid NOT NULL,profile_id uuid NOT NULL,created_by bigint NOT NULL REFERENCES users(id),target varchar(512) NOT NULL,context jsonb NOT NULL DEFAULT '{}'::jsonb,status varchar(20) NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','RUNNING','COMPLETED','CANCELLED','FAILED')),cancel_requested_at timestamptz,started_at timestamptz,finished_at timestamptz,error_code varchar(60),created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(agent_id,tenant_id) REFERENCES agents(id,tenant_id),FOREIGN KEY(profile_id,tenant_id) REFERENCES profiles(id,tenant_id),UNIQUE(id,tenant_id),UNIQUE(id,tenant_id,agent_id));
CREATE TABLE diagnostic_plans(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,diagnostic_id uuid NOT NULL UNIQUE,version integer NOT NULL,digest char(64) NOT NULL,document jsonb NOT NULL,created_at timestamptz NOT NULL,FOREIGN KEY(diagnostic_id,tenant_id) REFERENCES diagnostics(id,tenant_id));
CREATE TABLE diagnostic_steps(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,diagnostic_id uuid NOT NULL,key varchar(40) NOT NULL,logical_group varchar(40) NOT NULL,capability varchar(60) NOT NULL,wave integer NOT NULL,status varchar(20) NOT NULL CHECK(status IN ('PENDING','QUEUED','RUNNING','COMPLETED','FAILED','BLOCKED','SKIPPED','CANCELLED','EXPIRED')),optional boolean NOT NULL,definition jsonb NOT NULL,result jsonb,error_code varchar(60),started_at timestamptz,finished_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(diagnostic_id,tenant_id) REFERENCES diagnostics(id,tenant_id),UNIQUE(diagnostic_id,key),UNIQUE(id,tenant_id),UNIQUE(id,tenant_id,diagnostic_id));
CREATE TABLE agent_tasks(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,diagnostic_id uuid NOT NULL,step_id uuid NOT NULL UNIQUE,agent_id uuid NOT NULL,status varchar(20) NOT NULL CHECK(status IN ('QUEUED','CLAIMED','RUNNING','COMPLETED','FAILED','CANCELLED','EXPIRED')),generation integer NOT NULL DEFAULT 0,capability varchar(60) NOT NULL,input jsonb NOT NULL,timeout integer NOT NULL CHECK(timeout BETWEEN 1 AND 20),cancel_requested_at timestamptz,started_at timestamptz,finished_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(diagnostic_id,tenant_id,agent_id) REFERENCES diagnostics(id,tenant_id,agent_id),FOREIGN KEY(step_id,tenant_id,diagnostic_id) REFERENCES diagnostic_steps(id,tenant_id,diagnostic_id),UNIQUE(id,tenant_id));
CREATE TABLE agent_task_attempts(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,task_id uuid NOT NULL,generation integer NOT NULL,status varchar(20) NOT NULL CHECK(status IN ('CREATED','CLAIMED','RUNNING','COMPLETED','FAILED','EXPIRED')),lease_hash char(64) NOT NULL,lease_expires_at timestamptz NOT NULL,result_digest char(64),started_at timestamptz,finished_at timestamptz,created_at timestamptz NOT NULL,updated_at timestamptz NOT NULL,FOREIGN KEY(task_id,tenant_id) REFERENCES agent_tasks(id,tenant_id),UNIQUE(task_id,generation));
CREATE UNIQUE INDEX one_active_attempt ON agent_task_attempts(task_id) WHERE status IN ('CREATED','CLAIMED','RUNNING');
CREATE INDEX task_claim ON agent_tasks(tenant_id,agent_id,status,created_at);
CREATE INDEX attempt_expiration ON agent_task_attempts(tenant_id,lease_expires_at) WHERE status IN ('CLAIMED','RUNNING');
CREATE INDEX diagnostic_progress ON diagnostics(tenant_id,status,created_at);
CREATE TABLE diagnostic_conclusions(id uuid PRIMARY KEY,tenant_id bigint NOT NULL,diagnostic_id uuid NOT NULL UNIQUE,classification varchar(30) NOT NULL CHECK(classification IN ('NORMAL','INDICIO_DE_FALHA','INCONCLUSIVO','ERRO_DE_EXECUCAO')),summary text NOT NULL,evidence jsonb NOT NULL,next_steps jsonb NOT NULL,created_at timestamptz NOT NULL,FOREIGN KEY(diagnostic_id,tenant_id) REFERENCES diagnostics(id,tenant_id),CHECK(classification <> 'NORMAL' OR summary = 'Não foram encontradas anormalidades no escopo testado.'));
CREATE TRIGGER plans_immutable BEFORE UPDATE OR DELETE ON diagnostic_plans FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();
CREATE TRIGGER conclusions_immutable BEFORE UPDATE OR DELETE ON diagnostic_conclusions FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();
SQL);
        foreach (['skills', 'profiles', 'diagnostics', 'diagnostic_plans', 'diagnostic_steps', 'agent_tasks', 'agent_task_attempts', 'diagnostic_conclusions'] as $t) {
            DB::statement("ALTER TABLE $t ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $t FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $t FOR ALL USING (tenant_id::text = nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text = nullif(current_setting('app.tenant_id',true),''))");
        }
    }

    public function down(): void
    {
        foreach (['diagnostic_conclusions', 'agent_task_attempts', 'agent_tasks', 'diagnostic_steps', 'diagnostic_plans', 'diagnostics', 'profiles', 'skills'] as $t) {
            DB::statement("DROP TABLE $t");
        } DB::statement('ALTER TABLE agents DROP CONSTRAINT agents_id_tenant_unique');
    }
};
