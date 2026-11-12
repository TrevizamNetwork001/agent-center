<?php

namespace App\Noc\Analysis;

use App\Models\NocDevice;
use App\Noc\Catalog;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class Contracts
{
    public function all(): array
    {
        return app(Catalog::class)->all()['device_analysis']['contracts'];
    }

    public function platforms(): array
    {
        return app(Catalog::class)->all()['device_analysis']['platforms'];
    }

    public function labEnabled(?int $tenant = null): bool
    {
        $tenant ??= app(TenantContext::class)->tenantId;

        return in_array(config('app.env'), ['testing', 'local'], true) && config('device_analysis.lab_enabled') === true && in_array($tenant, config('device_analysis.lab_tenants', []), true);
    }

    public function adminDemoEnabled(): bool
    {
        $tenant = app(TenantContext::class)->tenantId;

        return $tenant !== null && config('device_analysis.admin_demo_enabled') === true && in_array($tenant, config('device_analysis.admin_demo_tenants', []), true);
    }

    public function platform(NocDevice $d): ?string
    {
        foreach ($this->platforms() as $id => $p) {
            if ($d->manufacturer === $p['manufacturer'] && $d->platform === $p['inventory_platform'] && preg_match('~'.$p['model_pattern'].'~iD', (string) $d->model) && preg_match('~'.$p['version_pattern'].'~iD', (string) $d->os_version)) {
                return $id;
            }
        }

        return null;
    }

    public function forDevice(NocDevice $d): array
    {
        $platform = $this->platform($d);
        $all = [];
        foreach ($this->all() as $c) {
            $compatible = $platform !== null && in_array($platform, $c['specialists'], true);
            $all[] = [...$c, 'compatibility' => $compatible ? 'LAB_DECLARED_COMPATIBLE' : 'UNAVAILABLE', 'specialist' => $platform, 'lab_enabled' => $compatible && $this->labEnabled(), 'admin_demo_enabled' => $compatible && $this->adminDemoEnabled(), 'operational_enabled' => false, 'limitation' => 'Identidade, hardware e capacidades reais não verificados. Não autoriza acesso.'];
        }

        return $all;
    }

    public function require(NocDevice $d, string $contract): array
    {
        $c = $this->all()[$contract] ?? null;
        $platform = $this->platform($d);
        if (! $c || ! $platform || ! in_array($platform, $c['specialists'], true)) {
            throw ValidationException::withMessages(['analysis' => 'Plataforma, modelo ou versão incompatível/não reconhecida para este contrato. RouterOS 6 e VRP 5 não são aceitos.']);
        }

        return [...$c, 'specialist' => $platform];
    }

    public function parameters(array $contract, array $input): array
    {
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > $contract['max_input_bytes']) {
            app(Schema::class)->invalid('input_size');
        }
        app(Schema::class)->check($input, $contract['input_schema'], 'parameters');

        return $input;
    }
}
