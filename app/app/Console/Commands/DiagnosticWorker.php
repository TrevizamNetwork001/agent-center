<?php

namespace App\Console\Commands;

use App\Diagnostics\Engine;
use App\Diagnostics\WorkerHealth;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class DiagnosticWorker extends Command
{
    protected $signature = 'agent-center:diagnostic-worker {--once}';

    protected $description = 'Deterministic diagnostic scheduler';

    public function handle(TenantContext $ctx, Engine $engine): int
    {
        $stop = false;
        $this->trap([SIGTERM, SIGINT], function () use (&$stop) {
            $stop = true;
        });
        $iterations = 0;
        $progress = 0;
        do {
            try {
                $ctx->apply(platform: true);
                $ids = Tenant::where('status', 'active')->pluck('id');
                foreach ($ids as $id) {
                    $ctx->apply(tenantId: $id);
                    $progress += $engine->tick();
                }$ctx->apply();
                WorkerHealth::write(++$iterations, $progress);
            } catch (Throwable) {
                $this->error('Diagnostic loop unavailable.');
                if ($this->option('once')) {
                    return 1;
                }
            } finally {
                $ctx->apply();
            }
            if ($this->option('once')) {
                break;
            }for ($i = 0; $i < 20 && ! $stop; $i++) {
                usleep(100000);
            }
        } while (! $stop);

        return 0;
    }
}
