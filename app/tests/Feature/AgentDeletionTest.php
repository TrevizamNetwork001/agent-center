<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AuditEvent;
use Tests\IdentityTestCase;

class AgentDeletionTest extends IdentityTestCase
{
    private function manualAgent(): Agent
    {
        $this->actingAs($this->admin)->post('/agents/enrollments', ['name' => 'Unused manual Agent'])->assertOk();
        $this->context()->forUser($this->admin);

        return Agent::sole();
    }

    public function test_revoked_manual_agent_can_be_removed_without_erasing_record_or_audit(): void
    {
        $agent = $this->manualAgent();
        $this->post('/agents/'.$agent->id.'/revoke')->assertRedirect();
        $this->get('/agents')->assertOk()->assertSee('Apagar');
        $this->delete('/agents/'.$agent->id)->assertRedirect('/agents');
        $this->get('/agents')->assertOk()->assertDontSee('Unused manual Agent');
        $this->get('/agents/'.$agent->id)->assertNotFound();
        $this->context()->forUser($this->admin);
        $this->assertSame(0, Agent::count());
        $this->assertNotNull(Agent::withTrashed()->findOrFail($agent->id)->deleted_at);
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.deleted')->count());
    }

    public function test_unrevoked_operator_and_other_tenant_deletion_are_refused(): void
    {
        $agent = $this->manualAgent();
        $this->delete('/agents/'.$agent->id)->assertForbidden();
        $this->post('/agents/'.$agent->id.'/revoke')->assertRedirect();
        $this->flushSession();
        $this->actingAs($this->operator)->delete('/agents/'.$agent->id)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->other)->delete('/agents/'.$agent->id)->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, Agent::withTrashed()->count());
        $this->context()->forUser($this->admin);
        $this->assertNull(Agent::findOrFail($agent->id)->deleted_at);
    }
}
