<?php

namespace App\Console\Commands;

use App\Llm\IdentityRegistry;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RegisterLlmIdentity extends Command
{
    protected $signature = 'agent-center:register-llm-identity {--tenant=} {--actor=} {--provider=} {--organization=} {--project=} {--tracking=} {--secret-event=}';

    protected $description = 'Record operator-attested OpenAI administrative IDs against the latest credential audit event; never reads secrets';

    public function handle(TenantContext $context, IdentityRegistry $registry): int
    {
        try {
            if (! ctype_digit((string) $this->option('tenant')) || ! ctype_digit((string) $this->option('actor'))) {
                $this->error('Tenant e ator administrativo são obrigatórios.');

                return self::FAILURE;
            }
            $tenant = (int) $this->option('tenant');
            // Resolve only the actor's own row before selecting a tenant. A platform
            // administrator has tenant_id NULL and is excluded by a tenant scope.
            $context->apply(userId: (int) $this->option('actor'));
            $actor = User::findOrFail((int) $this->option('actor'));
            $context->forUser($actor, $tenant);
            abort_unless($context->tenantId === $tenant, 403);
            $identity = $registry->register($actor, (string) $this->option('provider'), [
                'organization_id' => $this->option('organization'), 'project_id' => $this->option('project'),
                'tracking_id' => $this->option('tracking'), 'secret_event_id' => $this->option('secret-event'),
            ]);
            $this->info(json_encode($identity, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Registro recusado: conferir autorização, tenant, IDs e último evento de atualização. Nenhum secret foi acessado.');

            return self::FAILURE;
        } finally {
            $context->apply();
        }
    }
}
