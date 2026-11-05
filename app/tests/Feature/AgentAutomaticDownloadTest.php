<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Agent\Releases;
use App\Models\Agent;
use App\Models\AgentOperation;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentAutomaticDownloadTest extends IdentityTestCase
{
    private function agent(bool $download = true): array
    {
        $this->context()->forUser($this->admin);
        $token = Str::uuid().'.'.str_repeat('d', 64);
        $agent = new Agent;
        $updater = ['protocol' => 1, 'version' => '0.5.8', 'last_poll_at' => now()->toIso8601String()];
        if ($download) {
            $updater['download_protocol'] = 1;
        }
        $agent->forceFill(['id' => explode('.', $token)[0], 'tenant_id' => $this->a->id, 'name' => 'Automatic update', 'hostname' => 'lab', 'version' => '0.5.6', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'capabilities' => [], 'metadata' => ['updater' => $updater]])->save();

        return [$agent, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_update_opens_inline_and_ready_companion_never_redirects_to_manual_setup(): void
    {
        [$agent] = $this->agent();
        $this->actingAs($this->admin)->get('/agents/'.$agent->id)->assertOk()->assertSee('href="#agent-update"', false)->assertSee('<button>Atualizar Agent</button>', false)->assertSee('name="install_after_check" value="1"', false)->assertDontSee('Preparar atualização automática');
        $this->get('/agents/'.$agent->id.'/repair-updater')->assertRedirect(route('agents.show', $agent).'#agent-update');
        // Publishing a newer runtime is not a reason to repeat companion setup.
        $this->context()->forUser($this->admin);
        $metadata = $agent->metadata;
        $metadata['updater']['version'] = '0.5.7';
        $agent->forceFill(['metadata' => $metadata])->save();
        $this->assertTrue(Protocol::updaterReady($agent));
    }

    public function test_legacy_host_sees_an_explicit_one_time_setup_inside_update_panel(): void
    {
        [$agent] = $this->agent(false);
        $this->actingAs($this->admin)->get('/agents/'.$agent->id)->assertOk()->assertSee('href="#agent-update"', false)->assertSee('Preparar atualização automática')->assertDontSee('<button>Atualizar Agent</button>', false);
        $this->get('/agents/'.$agent->id.'/repair-updater')->assertOk()->assertSee('Preparação única nesta máquina');
    }

    public function test_stale_or_malformed_poll_does_not_ask_to_reinstall_download_capable_companion(): void
    {
        [$agent] = $this->agent();
        foreach ([now()->subMinutes(2)->toIso8601String(), 'invalid-timestamp'] as $timestamp) {
            $this->context()->forUser($this->admin);
            $metadata = $agent->metadata;
            $metadata['updater']['last_poll_at'] = $timestamp;
            $agent->forceFill(['metadata' => $metadata])->save();
            $this->actingAs($this->admin)->get('/agents/'.$agent->id)->assertOk()->assertSee('O atualizador está sem contato recente.')->assertDontSee('Preparar atualização automática');
        }
    }

    public function test_companion_reports_download_support_and_download_failure_is_preserved(): void
    {
        [$agent, $headers] = $this->agent(false);
        $release = app(Releases::class)->all()[0];
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/operations', ['kind' => 'update', 'release_sha' => $release['sha256'], 'reason' => 'maintenance', 'install_after_check' => 1])->assertRedirect();
        $this->context()->forUser($this->admin);
        $operation = AgentOperation::where('agent_id', $agent->id)->sole();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/operations/claim', ['updater_version' => '0.5.8', 'release_download_protocol' => 2], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/agent/operations/claim', ['updater_version' => '0.5.8', 'release_download_protocol' => 1], $headers)->assertOk()->assertJsonPath('operation.phase', 'precheck');
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation->id, 'status' => 'FAILED', 'error' => 'release_download_failed'], $headers)->assertOk();
        $this->context()->forUser($this->admin);
        $this->assertSame(1, $agent->fresh()->metadata['updater']['download_protocol']);
        $this->assertSame('release_download_failed', $operation->fresh()->error);
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentOperation::count());
        $this->flushSession();
        $this->actingAs($this->other)->get('/agents/'.$agent->id.'/repair-updater')->assertNotFound();
    }
}
