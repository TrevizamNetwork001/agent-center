<?php

namespace Tests\Feature;

use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Engine;
use App\Diagnostics\WorkerHealth;
use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\Profile;
use App\Services\Audit;
use App\Web\OperationalSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class WebUxTest extends IdentityTestCase
{
    private Agent $agent;

    private Diagnostic $normal;

    private Diagnostic $failed;

    private ?string $marker = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        $a = new Agent;
        $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'UX Agent A', 'hostname' => 'ux-agent-a', 'status' => 'active', 'version' => '0.5.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => ['agent_info', 'dns_lookup:v1', 'tcp_connect:v1', 'http_probe:v1'], 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now(), 'last_heartbeat_at' => now()->subSeconds(5), 'last_poll_at' => now()->subSeconds(3)])->save();
        $this->agent = $a;
        $this->normal = $this->fixture('alpha.example.test', 'NORMAL');
        $this->failed = $this->fixture('beta.example.test', 'INDICIO_DE_FALHA');
        $this->failed->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->marker = is_file(WorkerHealth::path()) ? file_get_contents(WorkerHealth::path()) : null;
        WorkerHealth::write(1, 0);
    }

    protected function tearDown(): void
    {
        if ($this->marker !== null) {
            file_put_contents(WorkerHealth::path(), $this->marker);
        } else {
            @unlink(WorkerHealth::path());
        }parent::tearDown();
    }

    private function fixture(string $target, string $classification): Diagnostic
    {
        $d = app(Engine::class)->create(Profile::where('key', 'SITE_LENTO')->sole(), $this->agent, $target, $this->admin);
        $d->forceFill(['status' => 'COMPLETED', 'started_at' => now()->subSeconds(8), 'finished_at' => now()])->save();
        $c = new DiagnosticConclusion;
        $c->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'diagnostic_id' => $d->id, 'classification' => $classification, 'summary' => $classification === 'NORMAL' ? Classifier::NORMAL : 'Indício no escopo de teste.', 'evidence' => [], 'next_steps' => ['Revisar evidências.'], 'created_at' => now()])->save();

        return $d;
    }

    public function test_dashboard_real_counts_recent_and_tenant_context(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Estado operacional')->assertSee('UX Agent A')->assertSee('alpha.example.test')->assertViewHas('health', fn ($h) => $h['agents_online'] === 1 && $h['agents_total'] === 1 && $h['today'] === 1 && $h['indications'] === 0 && $h['state'] === 'VERDE');
    }

    public function test_no_agents_never_looks_green_and_stale_poll_needs_attention(): void
    {
        $this->context()->forUser($this->other);
        $this->assertSame('ATENÇÃO', app(OperationalSnapshot::class)->get()['state']);
        $this->context()->forUser($this->admin);
        $this->agent->forceFill(['last_poll_at' => now()->subSeconds(40)])->save();
        $this->assertSame('ATENÇÃO', app(OperationalSnapshot::class)->get()['state']);
        unlink(WorkerHealth::path());
        $this->assertSame('NO-GO', app(OperationalSnapshot::class)->get()['state']);
    }

    public function test_dashboard_fragment_is_private_and_contains_no_whole_page(): void
    {
        $this->actingAs($this->admin)->get('/dashboard?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'dashboard-panel')->assertHeader('X-AC-Tenant', (string) $this->a->id)->assertDontSee('<html', false)->assertSee('Estado operacional')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_history_filters_classification_target_and_dates(): void
    {
        $this->actingAs($this->viewer)->get('/diagnostics/history?classification=NORMAL')->assertOk()->assertSee('alpha.example.test')->assertDontSee('beta.example.test');
        $this->get('/diagnostics/history?target=beta&status=COMPLETED')->assertOk()->assertSee('beta.example.test')->assertDontSee('alpha.example.test');
        $this->get('/diagnostics/history?from='.now()->toDateString().'&until='.now()->toDateString())->assertOk()->assertSee('alpha.example.test')->assertDontSee('beta.example.test');
    }

    public function test_history_agent_profile_and_invalid_filters(): void
    {
        $profile = Profile::where('key', 'PROBLEMA_DNS')->sole();
        $this->actingAs($this->viewer)->get('/diagnostics/history?profile_id='.$profile->id)->assertOk()->assertDontSee('alpha.example.test');
        $this->get('/diagnostics/history?agent_id='.Str::uuid())->assertOk()->assertDontSee('alpha.example.test');
        $this->getJson('/diagnostics/history?status=UNKNOWN')->assertUnprocessable();
        $this->getJson('/diagnostics/history?from=bad')->assertUnprocessable();
        $this->getJson('/diagnostics/history?from=2026-11-04&until=2026-01-01')->assertUnprocessable();
    }

    public function test_history_search_treats_wildcards_literally(): void
    {
        $this->actingAs($this->viewer)->get('/diagnostics/history?target=%25')->assertOk()->assertDontSee('alpha.example.test')->assertDontSee('beta.example.test');
    }

    public function test_agents_and_detail_never_expose_hash_or_token(): void
    {
        $this->actingAs($this->admin)->get('/agents')->assertOk()->assertSee('UX Agent A')->assertSee('Capabilities')->assertSee('ONLINE')->assertDontSee($this->agent->token_hash);
        $this->get('/agents/'.$this->agent->id)->assertOk()->assertSee('Device freeze')->assertDontSee($this->agent->token_hash);
        $this->get('/agents?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'agents-panel')->assertDontSee('Criar código de cadastro');
    }

    public function test_diagnostic_creation_auto_selects_only_agent_and_hides_protocol_internals(): void
    {
        $this->actingAs($this->operator)->get('/diagnostics/create')->assertOk()->assertSee('selecionado automaticamente')->assertDontSee('fencing')->assertDontSee('binding')->assertDontSee('provider')->assertSee('Perfil')->assertSee('Target');
    }

    public function test_diagnostic_result_and_progress_fragment(): void
    {
        $this->actingAs($this->viewer)->get('/diagnostics/'.$this->normal->id)->assertOk()->assertSee(Classifier::NORMAL)->assertSee('Próximos passos');
        $this->get('/diagnostics/'.$this->normal->id.'?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'diagnostic-panel')->assertSee('data-terminal', false)->assertDontSee('<html', false);
    }

    public function test_profiles_skills_and_fixture_review_are_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/profiles')->assertOk()->assertSee('Ver perfil');
        $this->context()->forUser($this->admin);
        $profile = Profile::where('key', 'SITE_LENTO')->sole();
        $this->get('/profiles/'.$profile->id)->assertOk()->assertSee('Composição')->assertSee('Testar perfil');
        $this->get('/skills')->assertOk()->assertSee('read-only')->assertSee('dns_lookup:v1');
        $this->flushSession();
        $this->actingAs($this->operator)->get('/profiles')->assertForbidden();
        $this->get('/skills')->assertForbidden();
        $this->get('/profiles/'.$profile->id)->assertForbidden();
    }

    public function test_health_details_rbac_and_fragment(): void
    {
        $this->actingAs($this->admin)->get('/operations')->assertOk()->assertSee('Leases vencidas')->assertSee('Engine')->assertSee('PostgreSQL');
        $this->get('/operations?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'operations-panel');
        $this->flushSession();
        $this->actingAs($this->viewer)->get('/operations?fragment=1')->assertForbidden();
    }

    public function test_read_pages_and_fragments_never_cross_tenants(): void
    {
        $this->actingAs($this->other);
        foreach (['/dashboard', '/dashboard?fragment=1', '/agents', '/agents?fragment=1', '/diagnostics/history', '/operations?fragment=1'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('UX Agent A')->assertDontSee('alpha.example.test')->assertDontSee('beta.example.test');
        }$this->get('/diagnostics/'.$this->normal->id.'?fragment=1')->assertNotFound();
        $this->get('/agents/'.$this->agent->id)->assertNotFound();
    }

    public function test_platform_admin_needs_tenant_for_operational_data(): void
    {
        $this->actingAs($this->platform)->get('/dashboard')->assertOk()->assertSee('Selecione um tenant')->assertDontSee('alpha.example.test');
        $this->get('/operations')->assertForbidden();
        $this->post('/tenant-context', ['tenant_id' => $this->a->id])->assertRedirect();
        $this->get('/dashboard')->assertOk()->assertSee('alpha.example.test');
    }

    public function test_audit_filters_and_unknown_metadata_not_rendered(): void
    {
        app(Audit::class)->record('user.updated', $this->admin, $this->a->id, 'user', $this->operator->id, ['fields' => ['name']]);
        $this->actingAs($this->admin)->get('/audit?event=user.updated')->assertOk()->assertSee('user.updated')->assertSee('Campos: name')->assertViewHas('events', fn ($events) => $events->count() === 1 && $events->first()->event_type === 'user.updated');
        $this->getJson('/audit?from=invalid')->assertUnprocessable();
    }

    public function test_list_queries_remain_bounded_as_records_grow(): void
    {
        $this->actingAs($this->admin);
        DB::enableQueryLog();
        $this->get('/diagnostics/history')->assertOk();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->context()->forUser($this->admin);
        for ($i = 0; $i < 12; $i++) {
            $this->fixture('fixture-'.$i.'.test', 'NORMAL');
        }DB::flushQueryLog();
        $this->get('/diagnostics/history')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 2, $large, 'List should eager load relations, not query per row.');
    }

    public function test_responsive_and_accessibility_structure(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('Ir para o conteúdo')->assertSee('aria-controls="sidebar"', false)->assertSee('agent-center.css')->assertSee('OBSERVAR')->assertSee('INVESTIGAR')->assertSee('ENSINAR')->assertSee('GOVERNAR')->assertSee('OPERAÇÕES');
        $this->get('/diagnostics/history')->assertOk()->assertSee('table-responsive', false)->assertSee('data-label="Perfil / alvo"', false);
    }

    public function test_fragment_pagination_never_navigates_to_a_bare_fragment(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $a = $this->agent->replicate();
            $a->forceFill(['id' => (string) Str::uuid(), 'name' => 'Pagination '.$i])->save();
        }
        $this->actingAs($this->admin)->get('/agents?fragment=1')->assertOk()->assertSee('page=2', false)->assertDontSee('fragment=1', false);
    }
}
