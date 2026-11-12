<?php

namespace Tests\Feature;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\IntentParser;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\Evidence;
use App\Noc\Analysis\Interpretation;
use App\Noc\Analysis\Simulation;
use App\Noc\Catalog;
use App\Noc\Inventory;
use App\Noc\Planning;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\IdentityTestCase;

class DeviceAnalysisTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->admin);
        Http::preventStrayRequests();
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id]]);
    }

    private function device(bool $mikro = false): NocDevice
    {
        return app(Inventory::class)->save($this->admin, ['name' => $mikro ? 'CCR' : 'NE8000', 'hostname' => $mikro ? 'ccr.lab.example' : 'ne8000.lab.example', 'management_ip' => $mikro ? '192.0.2.11' : '192.0.2.10', 'manufacturer' => $mikro ? 'mikrotik' : 'huawei', 'platform' => $mikro ? 'routeros7' : 'huawei-vrp', 'model' => $mikro ? 'CCR2004-16G-2S+' : 'NE8000 M4', 'os_version' => $mikro ? '7.16.2' : 'V800R023C00SPC500', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
    }

    private function saveSim(NocDevice $d, string $scenario, array $params = []): NocSimulation
    {
        return app(Inventory::class)->simulate($this->admin, $d, ($d->manufacturer === 'huawei' ? 'da-ne8000-' : 'da-ros7-').$scenario, (string) Str::uuid(), $params, $d->revision);
    }

    private function rejected(callable $f): void
    {
        try {
            $f();
            $this->fail('Invalid evidence accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('analysis', $e->errors());
        }
    }

    public function test_every_typed_fixture_on_both_platforms_and_original_catalog_unchanged(): void
    {
        $this->assertCount(25, app(Catalog::class)->all()['capabilities']);
        $this->assertCount(7, app(Catalog::class)->fixtures());
        $this->assertCount(7, app(Contracts::class)->all());
        foreach ([false, true] as $mikro) {
            $d = $this->device($mikro);
            $fixtures = app(Simulation::class)->fixtures($d);
            $this->assertCount(17, $fixtures);
            foreach ($fixtures as $key => $f) {
                $params = $f['contract_id'] === 'DEVICE_ROUTE_LOOKUP_READ' ? ['destination' => '8.8.8.8'] : [];
                $sim = app(Inventory::class)->simulate($this->admin, $d, $key, (string) Str::uuid(), $params, $d->revision);
                $r = $sim->result;
                $expected = match (substr($key, strlen($mikro ? 'da-ros7-' : 'da-ne8000-'))) {
                    'bgp-idle','bgp-active','bgp-unstable','route-absent','interface-down','interface-errors','cpu-high','memory-high' => 'ATTENTION',
                    'counters-accumulated','counters-reset','resources-no-reference','insufficient-data' => 'INCONCLUSIVE',
                    'device-unavailable' => 'UNAVAILABLE', default => 'NORMAL'
                };
                $this->assertSame($expected, $r['classification'], $key);
                $this->assertSame('SIMULATED', $r['mode'], $key);
                $this->assertSame('LAB', $r['environment']);
                $this->assertFalse($r['operational_enabled']);
                $this->assertNull($r['root_cause']);
                $this->assertSame('NOT_EXECUTED', $r['coverage']['operational']);
            }
        }
        $this->assertSame(34, NocSimulation::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        Http::assertNothingSent();
    }

    public function test_interpretation_uses_window_references_and_partial_coverage(): void
    {
        $d = $this->device();
        foreach (['bgp-established' => 'NORMAL', 'bgp-idle' => 'ATTENTION', 'bgp-active' => 'ATTENTION', 'bgp-unstable' => 'ATTENTION', 'route-found' => 'NORMAL', 'route-absent' => 'ATTENTION', 'interface-up' => 'NORMAL', 'interface-down' => 'ATTENTION', 'interface-errors' => 'ATTENTION', 'counters-accumulated' => 'INCONCLUSIVE', 'counters-reset' => 'INCONCLUSIVE', 'cpu-high' => 'ATTENTION', 'memory-high' => 'ATTENTION', 'resources-no-reference' => 'INCONCLUSIVE', 'insufficient-data' => 'INCONCLUSIVE', 'device-unavailable' => 'UNAVAILABLE'] as $s => $classification) {
            $r = $this->saveSim($d, $s, str_starts_with($s, 'route-') ? ['destination' => '8.8.8.8'] : [])->result;
            $this->assertSame($classification, $r['classification'], $s);
        }
        $this->assertSame('NORMAL', $this->saveSim($d, 'cpu-high', ['cpu_reference_percent' => 99])->result['classification']);
        $this->assertSame('PARTIAL', $this->saveSim($d, 'insufficient-data')->result['coverage']['status']);
        $this->assertSame('NONE', $this->saveSim($d, 'device-unavailable')->result['coverage']['status']);
        $r = $this->saveSim($d, 'bgp-established')->result;
        $c = app(Contracts::class)->require($d, 'DEVICE_BGP_PEERS_READ');
        $e = $r['evidence'];
        $e['data']['items'][0]['state'] = 'UNKNOWN';
        $this->assertSame('INCONCLUSIVE', app(Interpretation::class)->assess($c, $e)['classification']);
        $r = $this->saveSim($d, 'interface-errors')->result;
        $c = app(Contracts::class)->require($d, 'DEVICE_INTERFACE_COUNTERS_READ');
        $e = $r['evidence'];
        foreach ($e['data']['items'][0] as $k => $v) {
            if (str_starts_with($k, 'baseline_') && $k !== 'baseline_boot_id') {
                $e['data']['items'][0][$k] = $e['data']['items'][0][substr($k, 9)];
            }
        }$this->assertSame('NORMAL', app(Interpretation::class)->assess($c, $e)['classification']);
    }

    public function test_platform_identity_input_schema_and_evidence_are_closed(): void
    {
        $d = $this->device();
        $contracts = app(Contracts::class);
        $c = $contracts->require($d, 'DEVICE_BGP_PEERS_READ');
        $raw = app(Simulation::class)->fixtures()['da-ne8000-bgp-established']['raw'];
        foreach ([['os_version' => 'VRP 5'], ['os_version' => 'V800R099C00SPC500'], ['model' => 'NE40E'], ['manufacturer' => 'mikrotik']] as $bad) {
            $copy = clone $d;
            $copy->forceFill($bad);
            $this->rejected(fn () => $contracts->require($copy, $c['id']));
        }
        $ros = $this->device(true);
        $copy = clone $ros;
        $copy->os_version = '6.49.17';
        $this->rejected(fn () => $contracts->require($copy, $c['id']));
        foreach ([['command' => 'show all'], ['peer' => '8.8.8.8;reboot'], ['peer' => ['ip' => '192.0.2.2']]] as $params) {
            $this->rejected(fn () => $contracts->parameters($c, $params));
        }
        $cases = [];
        $bad = $raw;
        $bad['identity']['platform_family'] = 'VRP5';
        $cases[] = $bad;
        $bad = $raw;
        $bad['identity']['model'] = [];
        $cases[] = $bad;
        $bad = $raw;
        $bad['identity']['os_version'] = 'V800R022C00SPC600';
        $cases[] = $bad;
        $bad = $raw;
        $bad['payload']['command'] = 'show';
        $cases[] = $bad;
        $bad = $raw;
        $bad['payload']['peer_rows'][0]['uptime_seconds'] = -1;
        $cases[] = $bad;
        $bad = $raw;
        $bad['payload']['peer_rows'] = array_fill(0, 129, $raw['payload']['peer_rows'][0]);
        $cases[] = $bad;
        foreach ($cases as $bad) {
            $this->rejected(fn () => app(Evidence::class)->parse($d, $c, json_encode($bad)));
        }
        $this->rejected(fn () => app(Evidence::class)->parse($d, $c, str_repeat('x', 65537)));
        $this->rejected(fn () => app(Evidence::class)->parse($d, $c, '{invalid'));
    }

    public function test_rejects_contradictory_routes_duplicate_peers_and_routeros_key_collisions(): void
    {
        $d = $this->device();
        $fixtures = app(Simulation::class)->fixtures();
        $c = app(Contracts::class)->require($d, 'DEVICE_ROUTE_LOOKUP_READ');
        $raw = $fixtures['da-ne8000-route-found']['raw'];
        $raw['payload']['route_rows'][0]['prefix'] = '203.0.113.0/24';
        $this->rejected(fn () => app(Evidence::class)->parse($d, $c, json_encode($raw)));
        $c = app(Contracts::class)->require($d, 'DEVICE_BGP_PEERS_READ');
        $raw = $fixtures['da-ne8000-bgp-established']['raw'];
        $raw['payload']['peer_rows'][1]['peer'] = $raw['payload']['peer_rows'][0]['peer'];
        $this->rejected(fn () => app(Evidence::class)->parse($d, $c, json_encode($raw)));
        $ros = $this->device(true);
        $c = app(Contracts::class)->require($ros, 'DEVICE_BGP_PEERS_READ');
        $raw = $fixtures['da-ros7-bgp-established']['raw'];
        $raw['payload']['sessions'][0]['uptime_seconds'] = '1';
        $this->rejected(fn () => app(Evidence::class)->parse($ros, $c, json_encode($raw)));
    }

    public function test_resource_form_normalizes_only_typed_references_and_invalid_input_is_not_flashed(): void
    {
        $d = $this->device();
        $url = '/noc-inventory/'.$d->id.'/simulations';
        $this->actingAs($this->admin)->post($url, ['fixture' => 'da-ne8000-cpu-high', 'request_key' => (string) Str::uuid(), 'device_revision' => 1, 'parameters' => ['cpu_reference_percent' => '99', 'memory_reference_percent' => '']])->assertSessionHasNoErrors()->assertRedirect();
        $this->context()->forUser($this->admin);
        $r = NocSimulation::sole()->result;
        $this->assertSame('NORMAL', $r['classification']);
        $this->assertEquals(['cpu_reference_percent' => 99], $r['parameters']);
        $this->post($url, ['fixture' => 'da-ne8000-bgp-idle', 'request_key' => (string) Str::uuid(), 'device_revision' => 1, 'parameters' => ['command' => 'lab-forbidden-input']])->assertSessionHasErrors();
        $this->assertStringNotContainsString('lab-forbidden-input', json_encode(session()->getOldInput()));
    }

    public function test_native_web_form_revision_idempotence_rbac_csrf_and_tenant_boundaries(): void
    {
        $d = $this->device();
        $key = (string) Str::uuid();
        $body = ['fixture' => 'da-ne8000-bgp-idle', 'request_key' => $key, 'device_revision' => 1];
        $this->actingAs($this->admin)->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertRedirect();
        $this->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame(1, NocSimulation::count());
        $this->get('/noc-inventory/'.$d->id)->assertOk()->assertSee('SIMULATED / LAB')->assertSee('DEVICE_BGP_PEERS_READ')->assertSee('Classificação', false);
        $this->post('/noc-inventory/'.$d->id.'/simulations', [...$body, 'fixture' => 'da-ne8000-bgp-active'])->assertStatus(409);
        $this->postJson('/noc-inventory/'.$d->id.'/simulations', [...$body, 'request_key' => (string) Str::uuid(), 'parameters' => ['command' => 'forbidden']])->assertUnprocessable();
        $this->postJson('/noc-inventory/'.$d->id.'/simulations', [...$body, 'request_key' => (string) Str::uuid(), 'device_revision' => 2])->assertStatus(409);
        $this->flushSession();
        $this->actingAs($this->viewer)->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->other)->get('/noc-inventory/'.$d->id)->assertNotFound();
        $this->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, NocSimulation::count());
        $this->assertSame(0, DB::table('noc_simulations')->count());
        $this->flushSession();
        $this->actingAs($this->admin);
        $this->app->singleton(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertStatus(419);
    }

    public function test_real_intents_remain_unavailable_simulation_requires_explicit_lab_and_replay_is_safe(): void
    {
        $d = $this->device();
        $this->device(true);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $identity = Identity::web($this->admin);
        $engine = app(Engine::class);
        $c = $engine->create($identity, (string) Str::uuid());
        foreach (['Verifica os peers BGP do NE8000' => 'DEVICE_BGP_PEERS_READ', 'Tem alguma interface com erro no CCR?' => 'DEVICE_INTERFACE_COUNTERS_READ', 'Qual rota o NE8000 usa para 8.8.8.8?' => 'DEVICE_ROUTE_LOOKUP_READ', 'Como está a CPU do NE8000?' => 'DEVICE_RESOURCES_READ'] as $text => $contract) {
            $c = $engine->send($identity, $c, (string) Str::uuid(), $text);
            $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
            $this->assertSame($contract, $m->metadata['noc_planning']['analysis_contract_id']);
            $this->assertStringContainsString(Planning::UNAVAILABLE, $m->body);
            $this->assertArrayNotHasKey('pending', $c->context_json);
        }
        $this->assertSame('ROUTE_PATH_ANALYSIS', app(IntentParser::class)->parse('Como está o caminho até 8.8.8.8?')['intent']);
        $key = (string) Str::uuid();
        $c = $engine->send($identity, $c, $key, 'Simule bgp-idle no NE8000');
        $count = ConversationMessage::where('conversation_id', $c->id)->count();
        $engine->send($identity, $c, $key, 'Simule bgp-idle no NE8000');
        $this->assertSame($count, ConversationMessage::where('conversation_id', $c->id)->count());
        $this->assertSame(1, NocSimulation::count());
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertStringContainsString('SIMULATED / LAB', $m->body);
        $this->assertSame('ATTENTION', $m->metadata['classification']);
        $this->assertFalse($m->metadata['tracking']);
        config(['app.env' => 'production']);
        $engine->send($identity, $c, (string) Str::uuid(), 'Simule bgp-idle no NE8000');
        $this->assertSame(1, NocSimulation::count());
        $this->assertFalse(app(Contracts::class)->labEnabled());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        Http::assertNothingSent();
    }
}
