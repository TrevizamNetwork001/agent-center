<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'agent-center:create-admin {--email=} {--name=} {--tenant=} {--slug=}';

    protected $description = 'Cria somente o primeiro platform_admin com senha oculta e troca obrigatória';

    public function handle(TenantContext $context, Audit $audit): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Use terminal interativo; senha não pode ser argumento.');

            return self::FAILURE;
        }
        $context->apply(platform: true);
        try {
            if (User::withoutGlobalScopes()->where('role', Role::PlatformAdmin->value)->exists()) {
                $this->error('Bootstrap já concluído.');

                return self::FAILURE;
            }
            $data = ['email' => mb_strtolower(trim($this->option('email') ?: $this->ask('Email'))), 'name' => $this->option('name') ?: $this->ask('Nome'), 'tenant' => $this->option('tenant') ?: $this->ask('Nome do tenant inicial'), 'slug' => $this->option('slug') ?: $this->ask('Slug do tenant inicial'), 'password' => $this->secret('Senha temporária')];
            $validator = Validator::make($data, ['email' => ['required', 'email', 'max:254', 'unique:users'], 'name' => ['required', 'string', 'max:120'], 'tenant' => ['required', 'string', 'max:120'], 'slug' => ['required', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenants,slug'], 'password' => ['required', 'string', 'max:128', Password::min(14)->mixedCase()->numbers()->symbols()]]);
            if ($validator->fails()) {
                $this->error('Dados inválidos. Senha: mínimo 14 caracteres, maiúscula, minúscula, número e símbolo. Email e slug devem ser únicos.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($data, $audit) {
                DB::select('SELECT pg_advisory_xact_lock(48192671)');
                if (User::withoutGlobalScopes()->where('role', Role::PlatformAdmin->value)->exists()) {
                    throw new \RuntimeException('Bootstrap já concluído.');
                }
                $tenant = Tenant::create(['name' => $data['tenant'], 'slug' => $data['slug'], 'status' => 'active']);
                $user = new User;
                $user->forceFill(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'tenant_id' => null, 'role' => Role::PlatformAdmin, 'status' => 'active', 'must_change_password' => true])->save();
                $audit->record('tenant.created', $user, $tenant->id, 'tenant', $tenant->id);
                $audit->record('user.created', $user, null, 'user', $user->id, ['role' => 'platform_admin']);
            });
            $this->info('Administrador criado. Troca de senha obrigatória no primeiro login.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('Admin bootstrap failed', ['exception_type' => get_class($exception)]);
            $this->error('Bootstrap não concluído; nenhuma senha é registrada. Consulte o log sanitizado.');

            return self::FAILURE;
        } finally {
            $context->apply();
        }
    }
}
