<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE device_lab_accesses (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, device_id uuid NOT NULL,
 device_revision integer NOT NULL CHECK(device_revision>0), revision integer NOT NULL CHECK(revision>0),
 state varchar(16) NOT NULL CHECK(state IN ('approved_lab','revoked')),
 host_public_key text NOT NULL, secret_ciphertext text NOT NULL, secret_key_id varchar(16) NOT NULL,
 expires_at timestamptz NOT NULL, updated_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 FOREIGN KEY(device_id,tenant_id) REFERENCES noc_devices(id,tenant_id), UNIQUE(tenant_id,device_id)
);
ALTER TABLE device_lab_accesses ENABLE ROW LEVEL SECURITY;
ALTER TABLE device_lab_accesses FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_access ON device_lab_accesses USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''));
CREATE TRIGGER device_lab_accesses_no_delete BEFORE DELETE ON device_lab_accesses FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();
SQL);
    }

    public function down(): void
    {
        // Fail closed on populated tables. Code rollback must preserve credentials and audit history.
        DB::statement('ALTER TABLE device_lab_accesses ADD CONSTRAINT device_lab_accesses_rollback_empty CHECK(false)');
        DB::statement('DROP TABLE device_lab_accesses');
    }
};
