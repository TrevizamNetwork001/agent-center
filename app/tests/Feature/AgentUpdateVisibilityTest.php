<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Agent\Releases;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AuditEvent;
use App\Web\OperationalSnapshot;
use App\Web\Presentation;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentUpdateVisibilityTest extends IdentityTestCase
{
    public function test_verified_054_presence_remains_scoped_and_requires_freeze_and_recent_poll(): void
    {
        $this->context()->forUser($this->admin);
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Updated Agent', 'hostname' => 'host', 'version' => '0.5.5', 'status' => 'active', 'token_hash' => hash('sha256', Str::random(64)), 'last_heartbeat_at' => now(), 'last_poll_at' => now(), 'capabilities' => [], 'metadata' => ['telemetry' => ['device_operations_frozen' => true]]])->save();
        $this->assertSame('ONLINE', Presentation::agentStatus($agent));
        $this->assertSame(1, app(OperationalSnapshot::class)->get()['agents_online']);
        $agent->forceFill(['metadata' => ['telemetry' => ['device_operations_frozen' => false]]])->save();
        $this->assertSame('ATENÇÃO', Presentation::agentStatus($agent));
        $this->assertSame(0, app(OperationalSnapshot::class)->get()['agents_online']);
        $this->context()->forUser($this->other);
        $this->assertSame(0, app(OperationalSnapshot::class)->get()['agents_total']);
    }

    public function test_update_entry_and_pending_reason_are_visible_without_granting_operational_permissions(): void
    {
        $this->context()->forUser($this->admin);
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Update visibility', 'hostname' => 'host', 'version' => '0.5.3', 'status' => 'active', 'token_hash' => hash('sha256', Str::random(64)), 'capabilities' => [], 'metadata' => ['updater' => ['protocol' => 1, 'download_protocol' => 1, 'version' => Protocol::INSTALLER_VERSION, 'last_poll_at' => now()->toIso8601String()]]])->save();
        $url = '/agents/'.$agent->id;
        $this->actingAs($this->admin)->get('/agents')->assertOk()->assertSee('href="'.route('agents.show', $agent).'#agent-update"', false);
        $this->get($url)->assertOk()->assertSee('Atualizar Agent')->assertSee('id="agent-update"', false)->assertSee('<button>Atualizar Agent</button>', false);
        $release = app(Releases::class)->all()[0];
        $this->post($url.'/operations', ['kind' => 'update', 'release_sha' => $release['sha256'], 'reason' => 'repair'])->assertRedirect();
        $this->context()->forUser($this->admin);
        $agent->forceFill(['metadata' => []])->save();
        $this->get($url)->assertOk()->assertSee('Aguardando verificação no host')->assertSee('Uma preparação inicial habilita o download automático das próximas versões.')->assertDontSee('Detalhes técnicos e histórico')->assertDontSee('Confirmar operação</button>', false)->assertDontSee('<button>Atualizar Agent</button>', false)->assertSee('data-percent="0"', false)->assertSee('aria-valuenow="0"', false);
        $this->post($url.'/operations', ['kind' => 'update', 'release_sha' => $release['sha256'], 'reason' => 'repair'])
            ->assertRedirect(route('agents.show', $agent).'#agent-update')
            ->assertSessionHas('success', 'Já existe uma operação pendente para este Agent. Acompanhe o andamento abaixo.');
        $this->context()->forUser($this->admin);
        $this->assertSame(1, AgentOperation::where('agent_id', $agent->id)->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.update_requested')->where('metadata->agent_id', $agent->id)->count());
        $this->get($url.'/repair-updater')->assertOk()->assertSee('Atualizar Agent')->assertSee('bash instalar-agent.sh')->assertSee('Não reinstala o Agent nem cria outro cadastro.');
        $this->flushSession();
        $this->actingAs($this->operator)->get($url)->assertOk()->assertSee('Acompanhar atualização')->assertSee('A atualização exige um administrador do tenant.')->assertDontSee('<button>Atualizar Agent</button>', false);
        $this->post($url.'/operations', ['kind' => 'update', 'release_sha' => $release['sha256'], 'reason' => 'repair'])->assertForbidden();
        $this->get($url.'/repair-updater')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->other)->get($url)->assertNotFound();
        $this->get($url.'/repair-updater')->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->admin);
        $this->context()->forUser($this->admin);
        $operation = AgentOperation::where('agent_id', $agent->id)->sole();
        foreach (['PLANNED' => 1, 'APPROVED' => 2, 'RUNNING' => 2, 'COMPLETED' => 4] as $status => $completedStages) {
            $this->context()->forUser($this->admin);
            $operation->forceFill(['status' => $status])->save();
            $this->get($url.'?fragment=1')->assertOk()->assertSee('data-percent="'.($completedStages * 25).'"', false)->assertSee('aria-valuenow="'.($completedStages * 25).'"', false);
        }
    }

    public function test_version_comparison_hides_update_when_current_and_does_not_claim_unknown_versions_are_current(): void
    {
        $base = app(Releases::class)->all()[0];
        $this->mock(Releases::class, function ($mock) use ($base) {
            $mock->shouldReceive('all')->andReturn([
                array_replace($base, ['version' => '0.5.9']),
                array_replace($base, ['version' => '0.5.10']),
            ]);
        });
        $this->context()->forUser($this->admin);
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Version comparison', 'hostname' => 'host', 'version' => '0.5.9', 'status' => 'active', 'token_hash' => hash('sha256', Str::random(64)), 'capabilities' => [], 'metadata' => ['updater' => ['protocol' => 1, 'download_protocol' => 1, 'version' => Protocol::INSTALLER_VERSION, 'last_poll_at' => now()->toIso8601String()]]])->save();
        $this->actingAs($this->admin)->get('/agents/'.$agent->id)->assertOk()->assertSee('Nova versão disponível: 0.5.10.')->assertSee('<button>Atualizar Agent</button>', false);
        foreach (['0.5.10', '0.6.0'] as $version) {
            $this->context()->forUser($this->admin);
            $agent->forceFill(['version' => $version])->save();
            $this->get('/agents/'.$agent->id)->assertOk()->assertSee('Não há atualização disponível.')->assertDontSee('>Atualizar Agent</', false)->assertSee('Rollback controlado');
            $this->get('/agents')->assertOk()->assertSee('Não há atualização disponível.')->assertDontSee('>Atualizar Agent</', false);
        }
        $this->context()->forUser($this->admin);
        $agent->forceFill(['version' => 'unavailable'])->save();
        $this->get('/agents/'.$agent->id)->assertOk()->assertSee('Versão instalada indisponível para comparação.')->assertDontSee('Não há atualização disponível.')->assertDontSee('<button>Atualizar Agent</button>', false);
        $this->flushSession();
        $this->actingAs($this->other)->get('/agents/'.$agent->id)->assertNotFound();
    }
}
