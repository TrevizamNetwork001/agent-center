<?php

namespace App\Http\Controllers\Agent;

use App\Agent\ManagedSettings;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function update(Request $r, Agent $agent, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        $d = $r->validate(['revision' => ['required', 'integer', 'min:1', 'max:2147483646'], 'source_ips' => ['required', 'string', 'max:1024'], 'allowed_networks' => ['nullable', 'string', 'max:2048'], 'allowed_dns_names' => ['nullable', 'string', 'max:25500'], 'auto_public_targets' => ['required', 'boolean']]);
        $lines = fn ($key) => array_values(array_unique(preg_split('/[\s,;]+/', trim($d[$key] ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
        $ips = $lines('source_ips');
        if (! $ips || count($ips) > 16 || array_filter($ips, fn ($ip) => ! ManagedSettings::ip($ip))) {
            throw ValidationException::withMessages(['source_ips' => 'Informe de 1 a 16 IPs IPv4 ou IPv6, um por linha, sem máscaras.']);
        }
        $ips = array_values(array_unique(array_map([ManagedSettings::class, 'ip'], $ips)));
        $nets = $lines('allowed_networks');
        if (count($nets) > 32) {
            throw ValidationException::withMessages(['allowed_networks' => 'Máximo de 32 redes.']);
        }
        foreach ($nets as $net) {
            $p = explode('/', $net);
            if (count($p) !== 2 || ! ManagedSettings::ip($p[0]) || ! ctype_digit($p[1]) || (int) $p[1] > (str_contains($p[0], ':') ? 128 : 32)) {
                throw ValidationException::withMessages(['allowed_networks' => 'Informe redes em CIDR, por exemplo 192.168.10.0/24.']);
            }
        }
        $names = array_map(fn ($n) => strtolower(rtrim($n, '.')), $lines('allowed_dns_names'));
        if (count($names) > 100 || array_filter($names, fn ($n) => strlen($n) > 253 || ! filter_var($n, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw ValidationException::withMessages(['allowed_dns_names' => 'Informe até 100 domínios válidos.']);
        }
        DB::transaction(function () use ($r, $agent, $d, $ips, $nets, $names, $audit) {
            $a = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($a->status === 'active' && ! $a->revoked_at, 409);
            $old = ManagedSettings::current($a);
            abort_unless($old['revision'] === (int) $d['revision'], 409, 'A configuração mudou. Recarregue a página antes de salvar.');
            $settings = ['revision' => $old['revision'] + 1, 'source_ips' => $ips, 'auto_public_targets' => $r->boolean('auto_public_targets'), 'allowed_networks' => $nets, 'allowed_dns_names' => $names];
            $m = [...$a->metadata, 'managed_settings' => $settings, 'source_acl_enforced' => true];
            if (in_array($m['pending_source_ip'] ?? '', $ips, true)) {
                unset($m['pending_source_ip']);
            }
            $a->forceFill(['metadata' => $m])->save();
            $audit->record('agent.settings_updated', $r->user(), $a->tenant_id, 'agent', null, ['agent_id' => $a->id]);
        });

        return redirect()->to(route('agents.show', $agent).'#agent-network')->with('success', 'Configuração salva. A ACL já está ativa; o Agent receberá os ajustes no próximo contato.');
    }
}
