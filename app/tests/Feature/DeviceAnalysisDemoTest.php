<?php

namespace Tests\Feature;

use App\Conversation\DiagnosticExperience;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Models\ConversationSetting;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\Simulation;
use App\Noc\Analysis\SimulationScope;
use App\Noc\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IdentityTestCase;

class DeviceAnalysisDemoTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->admin);
        Http::preventStrayRequests();
        config(['app.env' => 'production', 'device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id], 'device_analysis.admin_demo_enabled' => true, 'device_analysis.admin_demo_tenants' => [$this->a->id]]);
        $this->app->instance('env', 'production');
    }

    private function device(bool $mikro = false): NocDevice
    {
        return app(Inventory::class)->save($this->admin, ['name' => $mikro ? 'CCR' : 'NE8000', 'hostname' => $mikro ? 'ccr.demo.example' : 'ne8000.demo.example', 'management_ip' => $mikro ? '192.0.2.31' : '192.0.2.30', 'manufacturer' => $mikro ? 'mikrotik' : 'huawei', 'platform' => $mikro ? 'routeros7' : 'huawei-vrp', 'model' => $mikro ? 'CCR2004-16G-2S+' : 'NE8000 M4', 'os_version' => $mikro ? '7.16.2' : 'V800R023C00SPC500', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'POP LAB', 'environment' => 'production', 'admin_status' => 'active']);
    }

    private function submit(NocDevice $d, array $overrides = [], ?User $u = null, bool $csrf = true, string $route = 'analysis-lab')
    {
        $this->flushSession();
        $token = Str::random(40);
        $this->actingAs($u ?? $this->admin)->withSession(['_token' => $token]);

        return $this->postJson('/noc-inventory/'.$d->id.'/'.$route, [...['fixture' => 'da-ne8000-bgp-idle', 'request_key' => (string) Str::uuid(), 'device_revision' => 1], ...($csrf ? ['_token' => $token] : []), ...$overrides]);
    }

    private function operationalSnapshot(): array
    {
        $out = [];
        foreach (DB::select("SELECT relname FROM pg_class JOIN pg_namespace n ON n.oid=relnamespace WHERE n.nspname='public' AND relkind='r' AND relforcerowsecurity AND relname NOT IN ('noc_simulations','audit_events') ORDER BY relname") as $t) {
            $table = DB::connection()->getQueryGrammar()->wrapTable($t->relname);
            $out[$t->relname] = DB::selectOne("SELECT md5(coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text,'[]')) AS hash FROM ".$table.' r')->hash;
        }

        return $out;
    }

    public function test_original_production_block_remains_for_conversations_even_with_old_flags(): void
    {
        $this->assertTrue(app()->environment('production'));
        $this->assertFalse(app(Contracts::class)->labEnabled());
        $d = $this->device();
        $this->submit($d, route: 'simulations')->assertForbidden();
        config(['device_analysis.admin_demo_enabled' => false]);
        $this->submit($d)->assertForbidden();
        $this->context()->forUser($this->admin);
        $this->assertSame(0, NocSimulation::count());
    }

    public function test_flag_tenant_admin_permission_authentication_and_csrf_are_required(): void
    {
        $d = $this->device();
        config(['device_analysis.admin_demo_tenants' => []]);
        $this->submit($d)->assertForbidden();
        config(['device_analysis.admin_demo_tenants' => [$this->a->id]]);
        $this->submit($d, u: $this->operator)->assertForbidden();
        $this->submit($d, u: $this->viewer)->assertForbidden();
        $this->submit($d, csrf: false)->assertStatus(419);
        $this->flushSession();
        auth()->forgetGuards();
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->postJson('/noc-inventory/'.$d->id.'/analysis-lab', ['_token' => $token])->assertUnauthorized();
    }

    public function test_all_34_scenarios_in_production_only_write_demo_rows_and_audit(): void
    {
        $devices = [$this->device(), $this->device(true)];
        $before = $this->operationalSnapshot();
        $processed = 0;
        foreach ($devices as $d) {
            foreach (app(Simulation::class)->fixtures($d) as $key => $fixture) {
                if (++$processed === 18) {
                    $this->travel(61)->seconds();
                }
                $params = $fixture['contract_id'] === 'DEVICE_ROUTE_LOOKUP_READ' ? ['destination' => '8.8.8.8'] : [];
                $this->submit($d, ['fixture' => $key, 'parameters' => $params])->assertRedirect();
                $this->context()->forUser($this->admin);
                $r = NocSimulation::where('fixture', $key)->sole()->result;
                $this->assertSame('ADMINISTRATIVE_DEMO', $r['simulation_scope']);
                $this->assertSame('SIMULADO', $r['display_mode']);
                foreach (['real_evidence', 'task_eligible', 'health_eligible', 'operational_enabled'] as $field) {
                    $this->assertFalse($r[$field]);
                }$this->assertSame('NOT_EXECUTED', $r['coverage']['operational']);
            }
        }
        $this->assertSame(34, NocSimulation::count());
        $this->assertSame($before, $this->operationalSnapshot());
        $this->assertSame(34, DB::table('audit_events')->where('event_type', 'noc.simulation_created')->where('metadata->simulation_scope', 'ADMINISTRATIVE_DEMO')->count());
        $this->flushSession();
        $this->actingAs($this->viewer)->get('/noc-inventory/'.$devices[0]->id)->assertOk()->assertViewHas('simulations', fn ($rows) => $rows->total() === 0);
        $this->flushSession();
        config(['device_analysis.admin_demo_enabled' => false]);
        $this->actingAs($this->admin)->get('/noc-inventory/'.$devices[0]->id)->assertOk()->assertViewHas('simulations', fn ($rows) => $rows->total() === 0);
        config(['device_analysis.admin_demo_enabled' => true]);
        $this->get('/noc-inventory/'.$devices[0]->id)->assertOk()->assertViewHas('simulations', fn ($rows) => $rows->total() === 17);
        Http::assertNothingSent();
    }

    public function test_native_rls_two_tenants_and_no_cross_scope_replay_or_flag_revocation(): void
    {
        $d = $this->device();
        $key = (string) Str::uuid();
        $this->submit($d, ['request_key' => $key])->assertRedirect();
        $this->submit($d, ['request_key' => $key])->assertRedirect();
        $this->submit($d, ['request_key' => $key, 'fixture' => 'da-ne8000-bgp-active'])->assertStatus(409);
        $this->submit($d, ['device_revision' => 2])->assertStatus(409);
        $this->submit($d, u: $this->other)->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, DB::table('noc_simulations')->count());
        $this->context()->forUser($this->admin);
        config(['device_analysis.admin_demo_enabled' => false]);
        $this->submit($d, ['request_key' => $key])->assertForbidden();
        $this->context()->forUser($this->admin);
        $this->assertSame(1, NocSimulation::count());
        config(['app.env' => 'local']);
        try {
            app(Inventory::class)->simulate($this->admin, $d, 'da-ne8000-bgp-idle', $key, [], 1);
            $this->fail('Cross-scope replay accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_closed_admin_endpoint_rejects_commands_destinations_and_untyped_context(): void
    {
        $d = $this->device();
        $this->submit($d, ['scope' => 'LAB_CONVERSATION'])->assertUnprocessable();
        $this->submit($d, ['parameters' => ['command' => 'not-permitted']])->assertUnprocessable();
        $this->submit($d, ['fixture' => 'da-ne8000-route-found', 'parameters' => ['destination' => '192.0.2.100']])->assertUnprocessable();
        $this->context()->forUser($this->admin);
        $this->assertSame(0, NocSimulation::count());
        Http::assertNothingSent();
    }

    public function test_administrative_evidence_is_rejected_by_conversation_presentation(): void
    {
        $d = $this->device();
        $sim = app(Inventory::class)->simulate($this->admin, $d, 'da-ne8000-bgp-idle', (string) Str::uuid(), [], 1, SimulationScope::AdministrativeDemo);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $i = Identity::web($this->admin);
        $engine = app(Engine::class);
        $c = $engine->create($i, (string) Str::uuid());
        foreach (['Verifique os peers BGP do NE8000.', 'Mostre a rota do CCR para 8.8.8.8.', 'Simule bgp-idle no NE8000'] as $text) {
            $c = $engine->send($i, $c, (string) Str::uuid(), $text);
        }
        $this->assertSame(1, NocSimulation::count());
        $this->assertArrayNotHasKey('pending', $c->context_json);
        config(['app.env' => 'local']);
        $this->expectException(HttpException::class);
        app(DiagnosticExperience::class)->simulationResult($c, $sim, $i);
    }
}
