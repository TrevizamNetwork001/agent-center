<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\NocSimulation;
use App\Noc\Inventory;
use App\Noc\ReadOnlyAdmin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class DeviceReadOnlyAdminTest extends IdentityTestCase
{
    public function test_production_administration_uses_existing_demo_and_never_grants_transport(): void
    {
        $this->context()->forUser($this->admin);
        Http::preventStrayRequests();
        config(['app.env' => 'production', 'device_analysis.admin_demo_enabled' => true, 'device_analysis.admin_demo_tenants' => [$this->a->id],
            'device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id], 'device_readonly_lab.enabled' => true, 'device_readonly_lab.tenants' => [$this->a->id]]);
        $d = app(Inventory::class)->save($this->admin, ['name' => 'NE8000', 'hostname' => 'admin.lab.example', 'management_ip' => '192.0.2.10', 'manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000 M4', 'os_version' => 'V800R023C00SPC500', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
        $meta = app(ReadOnlyAdmin::class)->forDevice($d);
        $this->assertCount(4, $meta['contracts']);
        $this->assertTrue($meta['device_freeze']);
        $this->assertFalse($meta['operational_enabled']);
        $this->assertFalse($meta['credentials_enabled']);
        $this->assertSame('NONE', $meta['transport']);
        $this->actingAs($this->admin)->get('/noc-inventory/'.$d->id)->assertOk()->assertSee('estrutura administrativa')->assertSee('acesso operacional CONGELADO')->assertDontSee('Consultar SSH SIMULADO');
        $this->post('/noc-inventory/'.$d->id.'/analysis-lab', ['fixture' => 'da-ne8000-bgp-idle', 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame('ADMINISTRATIVE_DEMO', NocSimulation::sole()->result['simulation_scope']);
        $this->assertSame(0, AgentTask::count());
        $this->flushSession();
        $this->actingAs($this->other)->get('/noc-inventory/'.$d->id)->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->viewer)->post('/noc-inventory/'.$d->id.'/analysis-lab', ['fixture' => 'da-ne8000-bgp-idle', 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertForbidden();
        Http::assertNothingSent();
    }
}
