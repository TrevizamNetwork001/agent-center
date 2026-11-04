<?php

namespace App\Console\Commands;

use App\Llm\SecretVault;
use App\Models\LlmProvider;
use App\Models\Tenant;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RotateLlmSecrets extends Command
{
    protected $signature = 'agent-center:rotate-llm-secrets {--apply : Reencrypt after a successful preflight}';

    protected $description = 'Preflight by default; rotate encrypted provider keys using the current master key';

    public function handle(TenantContext $ctx, SecretVault $vault): int
    {
        $checked = 0;
        $changed = 0;
        $ids = [];
        try {
            $ctx->apply(platform: true);
            $tenants = Tenant::pluck('id');
            foreach ($tenants as $tenant) {
                $ctx->apply(tenantId: $tenant);
                foreach (LlmProvider::whereNotNull('secret_ciphertext')->get() as $p) {
                    $checked++;
                    if ($vault->decrypt($p) === null) {
                        $this->error('Preflight failed; no changes applied.');

                        return 1;
                    }$ids[] = [$tenant, $p->id];
                }
            }
            if ($this->option('apply')) {
                foreach ($ids as [$tenant,$id]) {
                    $ctx->apply(tenantId: $tenant);
                    DB::transaction(function () use ($id, $vault, &$changed) {
                        $p = LlmProvider::whereKey($id)->lockForUpdate()->firstOrFail();
                        $secret = $vault->decrypt($p);
                        if ($secret === null) {
                            throw new \RuntimeException('Rotation stopped');
                        }$p->forceFill($vault->encrypt($secret, $p->id, $p->tenant_id))->save();
                        app(Audit::class)->record('llm.secret_updated', tenantId: $p->tenant_id, entity: 'provider', metadata: ['provider_id' => $p->id]);
                        $changed++;
                    });
                }
            }
            $this->info(json_encode(['checked' => $checked, 'changed' => $changed, 'dry_run' => ! $this->option('apply')]));

            return 0;
        } catch (\Throwable) {
            $this->error('Rotation incomplete; retain current and previous master keys.');

            return 1;
        } finally {
            $ctx->apply();
        }
    }
}
