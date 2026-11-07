<?php

namespace Tests\Feature;

use App\Agent\Releases;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AuditEvent;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentSingleConfirmationTest extends IdentityTestCase
{
    private function fixture(): array
    {
        $this->context()->forUser($this->admin);
        $token = Str::uuid().'.'.str_repeat('c', 64);
        $agent = new Agent;
        $agent->forceFill(['id' => explode('.', $token)[0], 'tenant_id' => $this->a->id, 'name' => 'Update fixture', 'hostname' => 'lab', 'version' => '0.5.3', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'capabilities' => []])->save();
        $release = app(Releases::class)->all()[0];
        $url = '/agents/'.$agent->id;

        return [$agent, ['Authorization' => 'Bearer '.$token], $release, $url];
    }

    private function requestUpdate(Agent $agent, array $release, string $url): AgentOperation
    {
        $this->actingAs($this->admin)->post($url.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $release['sha256'], 'install_after_check' => 1])->assertRedirect(route('agents.show', ['agent' => $agent, 'updating' => 1]));
        $this->context()->forUser($this->admin);
        $operation = AgentOperation::where('agent_id', $agent->id)->sole();
        $this->app['auth']->forgetGuards();

        return $operation;
    }

    private function planned(AgentOperation $operation, array $release): array
    {
        return ['operation_id' => $operation->id, 'status' => 'PLANNED', 'evidence' => ['version' => $release['version'], 'sha256' => $release['sha256'], 'freeze_preserved' => true]];
    }

    public function test_initial_confirmation_runs_verified_update_without_second_approval_and_retries_are_idempotent(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $operation = $this->requestUpdate($agent, $release, $url);
        $this->assertSame('PRECHECK_REQUESTED', $operation->status);
        $this->assertSame($this->admin->id, $operation->approved_by);
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation.phase', 'precheck');
        $report = $this->planned($operation, $release);
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertOk();
        $tampered = $report;
        $tampered['evidence']['sha256'] = str_repeat('0', 64);
        $this->postJson('/api/v1/agent/operations/report', $tampered, $headers)->assertConflict();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation.phase', 'apply');
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation', null);
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation->id, 'status' => 'COMPLETED', 'evidence' => [...$report['evidence'], 'postcheck' => true]], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation->id, 'status' => 'COMPLETED', 'evidence' => [...$report['evidence'], 'postcheck' => true, 'backup' => (string) Str::uuid()]], $headers)->assertOk();
        $this->context()->forUser($this->admin);
        $this->assertSame('COMPLETED', $operation->fresh()->status);
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.update_authorized')->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.update_approved')->count());
    }

    public function test_platform_admin_authorization_is_revalidated_without_leaking_context(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $this->actingAs($this->platform)->withSession(['active_tenant_id' => $this->a->id])->post($url.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $release['sha256'], 'install_after_check' => 1])->assertRedirect();
        $this->context()->forUser($this->admin);
        $operation = AgentOperation::where('agent_id', $agent->id)->sole();
        $this->assertSame($this->platform->id, $operation->approved_by);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/report', $this->planned($operation, $release), $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation.phase', 'apply');
        $this->assertNull($this->context()->tenantId);
        $this->assertNull($this->context()->userId);
        $this->assertFalse($this->context()->platform);
    }

    public function test_bad_precheck_evidence_cannot_authorize_installation(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $operation = $this->requestUpdate($agent, $release, $url);
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk();
        $report = $this->planned($operation, $release);
        $report['evidence']['sha256'] = str_repeat('0', 64);
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertUnprocessable();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation', null);
    }

    public function test_expired_initial_authorization_requires_review_instead_of_installing(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $operation = $this->requestUpdate($agent, $release, $url);
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/report', $this->planned($operation, $release), $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation', null);
        $this->context()->forUser($this->admin);
        $this->assertSame('PLANNED', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->approved_by);
        $this->assertNull($operation->fresh()->approved_at);
    }

    public function test_removed_admin_permission_requires_new_review(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $operation = $this->requestUpdate($agent, $release, $url);
        $this->context()->forUser($this->admin);
        $this->admin->forceFill(['role' => Role::Viewer])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/report', $this->planned($operation, $release), $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/claim', [], $headers)->assertOk()->assertJsonPath('operation', null);
    }

    public function test_initial_authorization_is_tenant_scoped_and_does_not_apply_to_rollback(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $payload = ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $release['sha256'], 'install_after_check' => 1];
        foreach ([$this->operator, $this->viewer] as $user) {
            $this->flushSession();
            $this->actingAs($user)->post($url.'/operations', $payload)->assertForbidden();
        }
        $this->flushSession();
        $this->actingAs($this->other)->post($url.'/operations', $payload)->assertNotFound();
        $this->get($url.'?updating=1')->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->admin)->post($url.'/operations', [...$payload, 'kind' => 'rollback'])->assertUnprocessable();
    }

    public function test_progress_page_has_return_target_and_live_completion_fragment(): void
    {
        [$agent, $headers, $release, $url] = $this->fixture();
        $operation = $this->requestUpdate($agent, $release, $url);
        $this->actingAs($this->admin)->get($url.'?updating=1')->assertOk()->assertSee('Atualizando Update fixture')->assertSee('data-completion-url="'.route('agents.show', $agent).'"', false)->assertDontSee('Revogar Agent');
        $this->context()->forUser($this->admin);
        $operation->forceFill(['status' => 'COMPLETED', 'finished_at' => now()])->save();
        $this->get($url.'?updating=1&fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'agent-detail')->assertSee('data-state="COMPLETED"', false)->assertSee('data-percent="100"', false);
    }
}
