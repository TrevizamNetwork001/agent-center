<?php

namespace App\Console\Commands;

use App\Models\AgentOperation;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResolveAgentOperation extends Command
{
    protected $signature = 'agent-center:resolve-operation {operation} {--tenant=} {--host-reviewed}';

    protected $description = 'Close an interrupted operation only after explicit host recovery review';

    public function handle(TenantContext $context, Audit $audit): int
    {
        if (! $this->option('host-reviewed') || ! Str::isUuid($this->argument('operation')) || ! ctype_digit((string) $this->option('tenant'))) {
            return 2;
        }
        try {
            $context->apply(tenantId: (int) $this->option('tenant'));
            DB::transaction(function () use ($audit) {
                $o = AgentOperation::whereKey($this->argument('operation'))->lockForUpdate()->firstOrFail();
                if (! in_array($o->status, ['RUNNING', 'PRECHECKING', 'UNCERTAIN'], true)) {
                    throw new \RuntimeException('Not interrupted');
                }
                $o->forceFill(['status' => 'FAILED', 'error' => 'interrupted', 'finished_at' => now()])->save();
                $audit->record('agent.'.$o->kind.'_failed', null, $o->tenant_id, 'agent', null, ['agent_id' => $o->agent_id, 'operation_id' => $o->id]);
            });
            $this->info('Interrupted operation closed after explicit host review; no runtime action executed.');

            return 0;
        } catch (\Throwable) {
            $this->error('Operation could not be resolved.');

            return 1;
        } finally {
            $context->apply();
        }
    }
}
