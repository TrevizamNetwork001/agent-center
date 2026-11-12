<?php

namespace Tests\Feature;

use App\Conversation\Engine as ConversationEngine;
use App\Conversation\Identity;
use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\OperationalIntent;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\NocDevice;
use App\Models\NocDeviceRevision;
use App\Models\NocSimulation;
use App\Noc\Catalog;
use App\Noc\Inventory;
use App\Noc\Planning;
use App\Noc\QuerySpecification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\IdentityTestCase;

class NocInventoryTest extends IdentityTestCase
{
    private function asUser($user): static
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function data(array $extra = []): array
    {
        return [...['name' => 'NE8000', 'hostname' => 'ne8000.pop.example', 'management_ip' => '192.0.2.10', 'manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000', 'os_version' => 'VRP demo', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'POP laboratório', 'environment' => 'lab', 'admin_status' => 'active', 'description' => 'Cadastro de demonstração', 'operational_notes' => 'Sem coleta real'], ...$extra];
    }

    private function createDevice(array $extra = []): NocDevice
    {
        $this->asUser($this->admin)->post('/noc-inventory', $this->data($extra))->assertRedirect();
        $this->context()->forUser($this->admin);

        return NocDevice::where('hostname', strtolower($extra['hostname'] ?? 'ne8000.pop.example'))->firstOrFail();
    }

    public function test_csrf_is_required_for_all_inventory_writes(): void
    {
        $d = $this->createDevice();
        $this->app->singleton(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->asUser($this->admin)->post('/noc-inventory', $this->data())->assertStatus(419);
        $this->put('/noc-inventory/'.$d->id, $this->data(['revision' => 1]))->assertStatus(419);
        $this->post('/noc-inventory/'.$d->id.'/deactivate', ['revision' => 1])->assertStatus(419);
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => (string) Str::uuid()])->assertStatus(419);
    }

    public function test_authenticated_creation_detail_audit_and_no_operational_authority(): void
    {
        $this->get('/noc-inventory')->assertRedirect('/login');
        $d = $this->createDevice();
        $this->get('/noc-inventory/'.$d->id)->assertOk()->assertSee('Integração operacional não habilitada')->assertSee('Histórico administrativo')->assertSee('Simulação controlada')->assertSee('REQUIRES_VALIDATION');
        $this->context()->forUser($this->admin);
        $this->assertSame(1, NocDeviceRevision::count());
        $event = DB::table('audit_events')->where('event_type', 'noc.device_created')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('192.0.2.10', $event->metadata);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, Agent::count());
        Http::assertNothingSent();
    }

    public function test_device_name_is_escaped_in_document_title_and_body(): void
    {
        $name = '</title><script>window.nocFixture=1</script>';
        $d = $this->createDevice(['name' => $name]);
        $this->get('/noc-inventory/'.$d->id)->assertOk()
            ->assertSee(e($name), false)
            ->assertDontSee('<script>window.nocFixture=1</script>', false);
    }

    public function test_edit_deactivate_history_and_stale_edit_conflict(): void
    {
        $d = $this->createDevice();
        $data = $this->data(['pop' => 'POP novo', 'revision' => 1]);
        $this->put('/noc-inventory/'.$d->id, $data)->assertRedirect();
        $this->put('/noc-inventory/'.$d->id, $data)->assertStatus(409);
        $this->post('/noc-inventory/'.$d->id.'/deactivate', ['revision' => 2])->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame('disabled', $d->fresh()->admin_status);
        $this->assertSame(3, $d->fresh()->revision);
        $this->assertSame(['created', 'updated', 'deactivated'], NocDeviceRevision::orderBy('revision')->pluck('action')->all());
        $this->assertSame('POP laboratório', NocDeviceRevision::orderBy('revision')->first()->snapshot['pop']);
        $this->delete('/noc-inventory/'.$d->id)->assertStatus(405);
    }

    public function test_duplicates_are_tenant_scoped_normalized_and_include_disabled_devices(): void
    {
        $d = $this->createDevice();
        $this->post('/noc-inventory/'.$d->id.'/deactivate', ['revision' => 1])->assertRedirect();
        foreach ([['name' => 'ne8000', 'hostname' => 'other.example', 'management_ip' => '192.0.2.11'], ['name' => 'Other', 'hostname' => 'NE8000.POP.EXAMPLE.', 'management_ip' => '192.0.2.12'], ['name' => 'Other', 'hostname' => 'other.example', 'management_ip' => '::ffff:192.0.2.10']] as $extra) {
            $this->post('/noc-inventory', $this->data($extra))->assertSessionHasErrors('inventory');
        }
        $this->flushSession();
        $this->asUser($this->other)->post('/noc-inventory', $this->data())->assertSessionHasNoErrors()->assertRedirect();
        $this->context()->forUser($this->other);
        $this->assertSame(1, NocDevice::count());
        $this->context()->forUser($this->admin);
        $this->assertSame(1, NocDevice::count());
    }

    public function test_validation_rejects_invalid_ips_hostnames_vendors_platforms_and_categories(): void
    {
        foreach ([['management_ip' => '999.8.8.8'], ['management_ip' => '192.0.2.0/24'], ['management_ip' => 'https://example.test'], ['hostname' => 'bad host'], ['hostname' => '-invalid.example'], ['manufacturer' => 'invented'], ['platform' => 'routeros7'], ['category' => 'unknown'], ['environment' => 'unknown']] as $bad) {
            $this->asUser($this->admin)->post('/noc-inventory', $this->data($bad))->assertSessionHasErrors(array_key_first($bad));
        }
        $this->context()->forUser($this->admin);
        $this->assertSame(0, NocDevice::count());
    }

    public function test_no_secrets_unknown_fields_or_request_ownership_are_accepted_or_flashed(): void
    {
        foreach ([['password' => 'fixture-sensitive-value'], ['tenant_id' => $this->b->id], ['created_by' => $this->other->id], ['commands' => ['arbitrary']], ['implementation_state' => 'AVAILABLE']] as $bad) {
            $this->asUser($this->admin)->post('/noc-inventory', $this->data($bad))->assertSessionHasErrors('inventory');
        }
        foreach (['name', 'description', 'operational_notes', 'os_version'] as $field) {
            $this->post('/noc-inventory', $this->data([$field => 'senha=Fixture-Not-A-Credential']))->assertSessionHasErrors($field);
            $this->assertEmpty(session()->getOldInput());
        }
        $this->context()->forUser($this->admin);
        $this->assertSame(0, NocDevice::count());
    }

    public function test_search_filters_and_read_only_viewer_operator_rbac(): void
    {
        $d = $this->createDevice();
        $this->createDevice(['name' => 'Switch acesso', 'hostname' => 'switch.pop.example', 'management_ip' => '192.0.2.20', 'category' => 'switch', 'network_role' => 'Acesso', 'pop' => 'POP B']);
        $this->asUser($this->viewer)->get('/noc-inventory?q=NE8000&category=router&manufacturer=huawei&pop=POP%20laborat%C3%B3rio&network_role=Borda')->assertOk()->assertSee('ne8000.pop.example')->assertDontSee('switch.pop.example');
        foreach ([$this->viewer, $this->operator] as $user) {
            $this->asUser($user)->get('/noc-inventory/'.$d->id)->assertOk();
            $this->get('/noc-inventory/'.$d->id.'/edit')->assertForbidden();
            $this->post('/noc-inventory', $this->data())->assertForbidden();
            $this->post('/noc-inventory/'.$d->id.'/deactivate', ['revision' => 1])->assertForbidden();
        }
        $this->asUser($this->viewer)->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->asUser($this->operator)->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => (string) Str::uuid()])->assertRedirect();
    }

    public function test_cross_tenant_binding_raw_rls_and_foreign_keys_fail_closed(): void
    {
        $d = $this->createDevice();
        $this->asUser($this->other)->get('/noc-inventory/'.$d->id)->assertNotFound();
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, DB::table('noc_devices')->count());
        $this->assertSame(0, DB::table('noc_device_revisions')->count());
        try {
            DB::transaction(fn () => DB::table('noc_simulations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->b->id, 'device_id' => $d->id, 'actor_id' => $this->other->id, 'request_key' => (string) Str::uuid(), 'fixture' => 'bgp-down', 'result' => json_encode(['source' => 'FIXTURE', 'simulation' => true]), 'created_at' => now()]));
            $this->fail('Cross-tenant device linked');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
        $this->context()->apply();
        foreach (['noc_devices', 'noc_device_revisions', 'noc_simulations'] as $t) {
            $this->assertSame(0, DB::table($t)->count());
            $flags = DB::selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname=?', [$t]);
            $this->assertTrue($flags->relrowsecurity && $flags->relforcerowsecurity);
            $this->assertFalse(DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS allowed', [$t, 'DELETE'])->allowed);
        }
    }

    public function test_all_fixtures_are_isolated_and_idempotent_with_no_network_tasks_or_real_health(): void
    {
        $devices = [];
        foreach (['router', 'switch', 'olt', 'bng'] as $i => $cat) {
            $devices[$cat] = $this->createDevice(['name' => 'Demo '.$cat, 'hostname' => $cat.'.example', 'management_ip' => '192.0.2.'.(40 + $i), 'category' => $cat, 'platform' => $cat === 'olt' ? 'huawei-olt' : 'huawei-vrp']);
        }
        $catalog = app(Catalog::class);
        foreach ($catalog->fixtures() as $key => $fixture) {
            $d = $devices[match ($key) {
                'interface-crc' => 'switch','onu-offline','optical-low' => 'olt','pppoe-disconnected' => 'bng',default => 'router'
            }];
            $body = ['fixture' => $key, 'request_key' => (string) Str::uuid()];
            $this->asUser($this->admin)->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertRedirect();
            $this->post('/noc-inventory/'.$d->id.'/simulations', $body)->assertRedirect();
        }
        $this->context()->forUser($this->admin);
        $this->assertSame(7, NocSimulation::count());
        foreach (NocSimulation::all() as $sim) {
            $this->assertTrue($sim->is_simulated);
            $this->assertTrue($sim->result['simulation']);
            $this->assertSame('FIXTURE', $sim->result['source']);
            $this->assertFalse($sim->result['operational_enabled']);
        }
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, ConversationMessage::count());
        Http::assertNothingSent();
        $this->asUser($this->admin)->get('/noc-inventory/'.$devices['olt']->id)->assertOk()->assertSee('SIMULADO')->assertSee('fictícia');
    }

    public function test_forged_real_result_incompatible_fixture_and_replayed_key_are_rejected(): void
    {
        $d = $this->createDevice();
        $key = (string) Str::uuid();
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => $key])->assertRedirect();
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-established', 'request_key' => $key])->assertStatus(409);
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'optical-low', 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('fixture');
        $this->post('/noc-inventory/'.$d->id.'/simulations', ['fixture' => 'bgp-down', 'request_key' => (string) Str::uuid(), 'result' => ['simulation' => false]])->assertSessionHasErrors('result');
        $this->context()->forUser($this->admin);
        $this->assertSame(1, NocSimulation::count());
    }

    public function test_append_only_history_and_simulations_cannot_be_changed(): void
    {
        $d = $this->createDevice();
        $sim = app(Inventory::class)->simulate($this->admin, $d, 'bgp-down', (string) Str::uuid());
        foreach ([['noc_device_revisions', 'revision', 99], ['noc_simulations', 'fixture', 'bgp-established']] as [$table,$field,$value]) {
            try {
                DB::transaction(fn () => DB::table($table)->update([$field => $value]));
                $this->fail('History changed');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo[0]);
            }
        }
        $this->assertSame('bgp-down', $sim->fresh()->fixture);
    }

    public function test_catalog_never_claims_operational_availability_and_considers_model_version(): void
    {
        $d = $this->createDevice(['os_version' => null]);
        $catalog = app(Catalog::class);
        $this->assertCount(25, $catalog->all()['capabilities']);
        foreach ($catalog->forDevice($d) as $cap) {
            $this->assertNotSame('AVAILABLE', $cap['implementation_state']);
            $this->assertFalse($cap['operational_enabled']);
            $this->assertStringContainsString('versão não informada', $cap['device_validation']);
            $this->assertNotEmpty($cap['evidence_schema']['fields']);
        }
        $this->get('/noc-inventory/capabilities')->assertOk()->assertSee('RouterOS 6')->assertSee('RouterOS 7')->assertSee('Cisco IOS-XR')->assertSee('FiberHome OLT');
    }

    public function test_future_query_contract_has_only_closed_data_and_no_execution_authority(): void
    {
        $spec = new QuerySpecification($this->a->id, (string) Str::uuid(), 'routing.table', 1, ['destination' => '8.8.8.8']);
        $this->assertFalse(method_exists($spec, 'execute'));
        $this->assertFalse(method_exists($spec, 'connect'));
        $this->expectException(\InvalidArgumentException::class);
        new QuerySpecification($this->a->id, (string) Str::uuid(), 'routing.table', 1, ['command' => 'anything']);
    }

    public function test_equipment_objective_has_no_vm_profile_and_does_not_publish_knowledge(): void
    {
        $this->context()->forUser($this->admin);
        $before = DB::table('knowledge_items')->count();
        $plan = app(IntentPlanning::class)->devicePlan(app(IntentParser::class)->parse('Verifique o BGP do NE8000'), 'Verifique o BGP do NE8000', $this->admin);
        $this->assertSame('UNAVAILABLE', $plan['state']);
        $this->assertSame('UNKNOWN', $plan['authorization']);
        $this->assertSame($before, DB::table('knowledge_items')->count());
        $this->expectException(ValidationException::class);
        app(IntentPlanning::class)->profile(OperationalIntent::DeviceBgp, null);
    }

    public function test_device_intents_cannot_fall_back_to_vm_profiles_or_preflight(): void
    {
        $d = $this->createDevice();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $punctuated = app(IntentPlanning::class)->devicePlan(app(IntentParser::class)->parse('Verifique o BGP do NE8000.'), 'Verifique o BGP do NE8000.', $this->admin);
        $this->assertSame($d->id, $punctuated['device_id']);
        $engine = app(ConversationEngine::class);
        $identity = Identity::web($this->admin);
        $c = $engine->create($identity, (string) Str::uuid());
        foreach (['Verifique o BGP do NE8000' => 'BGP_SESSION_ANALYSIS', 'Confira a rota até 8.8.8.8 no roteador' => 'ROUTING_TABLE_LOOKUP', 'Verifique a potência das ONUs' => 'OPTICAL_SIGNAL_ANALYSIS', 'Verifique as interfaces do roteador' => 'DEVICE_INTERFACE_ANALYSIS', 'Confira as sessões PPPoE' => 'PPPOE_SESSION_ANALYSIS', 'Verifique o status das ONUs' => 'ONU_STATUS_ANALYSIS'] as $text => $intent) {
            $parsed = app(IntentParser::class)->parse($text);
            $this->assertSame($intent, $parsed['intent']);
            $c = $engine->send($identity, $c, (string) Str::uuid(), $text);
            $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
            $this->assertStringContainsString(Planning::UNAVAILABLE, $m->body);
            $this->assertSame($intent, $m->metadata['noc_planning']['intent']);
            $this->assertSame([], $m->metadata['noc_planning']['executed_steps']);
            $this->assertArrayNotHasKey('pending', $c->context_json);
            $this->assertArrayNotHasKey('preflight_watch', $c->context_json);
        }
        $this->assertSame('ROUTE_PATH_ANALYSIS', app(IntentParser::class)->parse('Analise o caminho até 8.8.8.8')['intent']);
        $c = $engine->send($identity, $c, (string) Str::uuid(), 'Analise o caminho até 8.8.8.8 no roteador');
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertSame('NEEDS_CLARIFICATION', $m->metadata['noc_planning']['state']);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        Http::assertNothingSent();
    }
}
