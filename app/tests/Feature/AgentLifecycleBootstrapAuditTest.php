<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Services\Audit;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentLifecycleBootstrapAuditTest extends IdentityTestCase
{
    public function test_lifecycle_bootstrap_is_audited_without_secrets_and_is_tenant_isolated(): void
    {
        $this->context()->forUser($this->admin);
        $operation = (string) Str::uuid();
        foreach (['agent.lifecycle_bootstrap_authorized', 'agent.lifecycle_bootstrap_completed'] as $event) {
            app(Audit::class)->record($event, $this->admin, $this->a->id, 'agent', null,
                ['operation_id' => $operation, 'password' => 'never-persist', 'token' => 'never-persist']);
        }
        $rows = AuditEvent::where('metadata->operation_id', $operation)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['operation_id' => $operation], $row->metadata);
        }
        $this->context()->forUser($this->other);
        $this->assertSame(0, AuditEvent::where('metadata->operation_id', $operation)->count());
    }
}
