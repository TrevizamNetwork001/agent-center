<?php

namespace Tests\Feature;

use App\Models\Agent;
use Illuminate\Support\Facades\Cache;
use Tests\IdentityTestCase;

class AgentBootstrapTest extends IdentityTestCase
{
    private function requestMachine(): array
    {
        return $this->postJson('/api/v1/agent/bootstrap/request', ['hostname' => 'client-linux', 'fingerprint' => str_repeat('a', 64)])->assertStatus(202)->assertJsonMissingPath('code')->assertJsonMissingPath('token')->json();
    }

    public function test_request_requires_platform_acceptance_before_credentials_and_uses_selected_tenant(): void
    {
        $pending = $this->requestMachine();
        $this->postJson('/api/v1/agent/bootstrap/status', ['receipt' => $pending['receipt']])->assertOk()->assertJsonPath('state', 'AWAITING_APPROVAL')->assertJsonMissingPath('code');
        $this->actingAs($this->platform)->get('/agent-requests')->assertOk()->assertSee('client-linux')->assertDontSee($pending['receipt']);
        $this->get('/agent-requests?fragment=1')->assertOk()->assertHeader('X-AC-Fragment', 'agent-requests')->assertHeader('X-AC-Tenant', 'platform')->assertSee('client-linux');
        $this->get('/agent-requests')->assertSee('data-tenant="platform"', false);
        $this->post('/agent-requests/'.$pending['request_id'].'/accept', ['tenant_id' => $this->b->id, 'name' => 'Client B Linux'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $approved = $this->postJson('/api/v1/agent/bootstrap/status', ['receipt' => $pending['receipt']])->assertOk()->assertJsonPath('state', 'APPROVED')->assertJsonMissingPath('token')->json();
        $row = Cache::get('agent-bootstrap:request:'.$pending['request_id']);
        $this->assertNotSame($approved['code'], $row['code']);
        $enrollment = $this->postJson('/api/v1/agent/enroll', ['code' => $approved['code'], 'hostname' => 'client-linux', 'version' => '0.5.3'])->assertStatus(202)->json();
        $identity = $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $enrollment['receipt']])->assertOk()->assertJsonPath('state', 'ENROLLED')->json();
        $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $enrollment['receipt']])->assertConflict();
        $this->context()->forUser($this->admin);
        $this->assertSame(0, Agent::count());
        $this->context()->forUser($this->other);
        $this->assertSame(1, Agent::count());
        $this->assertArrayHasKey('approved', Agent::firstOrFail()->metadata['onboarding']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.3'], ['Authorization' => 'Bearer '.$identity['token']])->assertOk();
    }

    public function test_tenant_accounts_cannot_list_or_accept_and_receipt_cannot_be_guessed(): void
    {
        $pending = $this->requestMachine();
        foreach ([$this->admin, $this->other, $this->operator, $this->viewer] as $user) {
            $this->flushSession();
            $this->actingAs($user)->get('/agent-requests')->assertForbidden();
            $this->post('/agent-requests/'.$pending['request_id'].'/accept', ['tenant_id' => $this->a->id, 'name' => 'forbidden'])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $receipt = $pending['request_id'].'.'.str_repeat('f', 64);
        $this->postJson('/api/v1/agent/bootstrap/status', ['receipt' => $receipt])->assertUnauthorized();
        $this->postJson('/api/v1/agent/bootstrap/request', ['hostname' => 'linux', 'fingerprint' => str_repeat('a', 64), 'tenant_id' => $this->a->id])->assertUnprocessable();
    }

    public function test_expired_duplicate_and_invalid_tenant_acceptance_are_refused(): void
    {
        $pending = $this->requestMachine();
        $this->actingAs($this->platform)->post('/agent-requests/'.$pending['request_id'].'/accept', ['tenant_id' => 999999, 'name' => 'invalid'])->assertStatus(422);
        $this->post('/agent-requests/'.$pending['request_id'].'/accept', ['tenant_id' => $this->a->id, 'name' => 'valid'])->assertRedirect();
        $this->post('/agent-requests/'.$pending['request_id'].'/accept', ['tenant_id' => $this->b->id, 'name' => 'duplicate'])->assertConflict();
        $this->app['auth']->forgetGuards();
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/agent/bootstrap/status', ['receipt' => $pending['receipt']])->assertUnauthorized();
    }
}
