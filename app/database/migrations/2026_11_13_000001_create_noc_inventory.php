<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE noc_devices (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES tenants(id),
 name varchar(120) NOT NULL, hostname varchar(253) NOT NULL, management_ip inet NOT NULL,
 manufacturer varchar(24) NOT NULL, platform varchar(48) NOT NULL, model varchar(120) NOT NULL,
 os_version varchar(120), category varchar(24) NOT NULL CHECK(category IN ('router','bng','switch','olt','firewall','radio','infrastructure','other')),
 network_role varchar(120) NOT NULL, pop varchar(120) NOT NULL, environment varchar(16) NOT NULL CHECK(environment IN ('production','staging','lab')),
 description text, admin_status varchar(16) NOT NULL CHECK(admin_status IN ('active','disabled','planned')), operational_notes text,
 revision integer NOT NULL DEFAULT 1 CHECK(revision>0), created_by bigint NOT NULL REFERENCES users(id), updated_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(id,tenant_id), UNIQUE(tenant_id,hostname), UNIQUE(tenant_id,management_ip),
 CHECK(masklen(management_ip) = CASE family(management_ip) WHEN 4 THEN 32 ELSE 128 END),
 CHECK((manufacturer = 'huawei' AND platform IN ('huawei-vrp','huawei-olt')) OR (manufacturer = 'mikrotik' AND platform IN ('routeros6','routeros7')) OR (manufacturer = 'cisco' AND platform IN ('cisco-ios','cisco-ios-xe','cisco-ios-xr','cisco-nx-os')) OR (manufacturer = 'datacom' AND platform IN ('datacom-dmos')) OR (manufacturer = 'fiberhome' AND platform IN ('fiberhome-olt')) OR (manufacturer = 'zte' AND platform IN ('zte-olt')) OR (manufacturer = 'vsol' AND platform IN ('vsol-olt')) OR (manufacturer = 'intelbras' AND platform IN ('intelbras-olt')) OR (manufacturer = 'other' AND platform IN ('other-unverified')))
);
CREATE UNIQUE INDEX noc_device_name_unique ON noc_devices(tenant_id,lower(name));
CREATE INDEX noc_device_filters ON noc_devices(tenant_id,category,manufacturer,pop,network_role);
CREATE TABLE noc_device_revisions (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, device_id uuid NOT NULL, revision integer NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), action varchar(16) NOT NULL CHECK(action IN ('created','updated','deactivated')),
 snapshot jsonb NOT NULL, created_at timestamptz NOT NULL,
 FOREIGN KEY(device_id,tenant_id) REFERENCES noc_devices(id,tenant_id), UNIQUE(tenant_id,device_id,revision)
);
CREATE TABLE noc_simulations (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, device_id uuid NOT NULL, actor_id bigint NOT NULL REFERENCES users(id),
 request_key uuid NOT NULL, fixture varchar(64) NOT NULL, result jsonb NOT NULL,
 is_simulated boolean NOT NULL DEFAULT true CHECK(is_simulated=true), created_at timestamptz NOT NULL,
 FOREIGN KEY(device_id,tenant_id) REFERENCES noc_devices(id,tenant_id), UNIQUE(tenant_id,actor_id,request_key),
 CHECK((result->>'source'='FIXTURE' AND result->>'simulation'='true') IS TRUE)
);
CREATE INDEX noc_revision_history ON noc_device_revisions(tenant_id,device_id,revision DESC);
CREATE INDEX noc_simulation_history ON noc_simulations(tenant_id,device_id,created_at DESC);
SQL);
        foreach (['noc_devices', 'noc_device_revisions', 'noc_simulations'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $table USING(tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK(tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
            DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation()");
        }
        foreach (['noc_device_revisions', 'noc_simulations'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation()");
        }
    }

    public function down(): void
    {
        // Roll code back first, retaining populated tables. Never silently erase administrative history.
        foreach (['noc_devices', 'noc_device_revisions', 'noc_simulations'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_rollback_empty CHECK(false)");
        }

        DB::unprepared('DROP TABLE noc_simulations; DROP TABLE noc_device_revisions; DROP TABLE noc_devices;');
    }
};
