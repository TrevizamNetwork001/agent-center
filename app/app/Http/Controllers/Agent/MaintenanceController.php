<?php

namespace App\Http\Controllers\Agent;

use App\Agent\MaintenanceSsh;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentMaintenanceAccess;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    public function show(Agent $agent)
    {
        Gate::authorize('revoke', $agent);
        abort_unless($agent->name === 'teste', 403);

        return response()->view('agents.maintenance', ['agent' => $agent, 'access' => AgentMaintenanceAccess::where('agent_id', $agent->id)->first()])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $r, Agent $agent, MaintenanceSsh $ssh, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        abort_unless($r->secure() || app()->environment('testing'), 403, 'HTTPS obrigatório para manutenção.');
        $data = $r->validate(['host' => ['required', 'ip'], 'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'regex:/^[a-z_][a-z0-9_-]{0,31}$/D'], 'password' => ['required', 'string', 'max:1024'], 'revision' => ['required', 'integer', 'min:0']]);
        abort_unless($ssh->targetAllowed($agent, $data['host']), 403, 'Destino não autorizado para manutenção.');
        $existing = AgentMaintenanceAccess::where('agent_id', $agent->id)->first();
        abort_unless((int) ($existing?->revision ?? 0) === (int) $data['revision'], 409);
        $pins = config('agent_maintenance.host_fingerprints', []);
        $fingerprint = $existing && $existing->host === $data['host'] && $existing->port === (int) $data['port']
            ? $existing->host_fingerprint : ($pins[$agent->id][$data['host'].':'.$data['port']] ?? null);
        if (! is_string($fingerprint) || ! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $fingerprint)) {
            throw ValidationException::withMessages(['host' => 'Identidade SSH aguardando configuração pelo administrador da Central.']);
        }
        DB::transaction(function () use ($r, $agent, $data, $fingerprint, $audit) {
            $current = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'active' && ! $current->revoked_at && $current->name === 'teste', 409);
            $access = AgentMaintenanceAccess::where('agent_id', $agent->id)->lockForUpdate()->first();
            abort_unless((int) ($access?->revision ?? 0) === (int) $data['revision'], 409);
            $access ??= new AgentMaintenanceAccess;
            $access->forceFill(['id' => $access->id ?? (string) Str::uuid(), 'tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id,
                'created_by' => $r->user()->id, 'host' => $data['host'], 'port' => $data['port'], 'username' => $data['username'],
                'host_fingerprint' => $fingerprint, 'private_key' => null, 'password' => $data['password'], 'revision' => (int) $data['revision'] + 1,
                'expires_at' => now()->addHour(), 'check_status' => null, 'checked_at' => null])->save();
            $audit->record('agent.maintenance_saved', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id]);
        });

        return redirect()->route('agents.maintenance', $agent)->with('success', 'Acesso salvo por uma hora. Nenhuma instalação foi executada.');
    }

    public function check(Request $r, Agent $agent, MaintenanceSsh $ssh, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        abort_unless($r->secure() || app()->environment('testing'), 403, 'HTTPS obrigatório para manutenção.');
        $r->validate(['revision' => ['required', 'integer', 'min:1']]);
        $access = AgentMaintenanceAccess::where('agent_id', $agent->id)->firstOrFail();
        abort_unless($access->revision === (int) $r->input('revision'), 409);
        abort_unless($ssh->targetAllowed($agent, $access->host), 403);
        $status = $ssh->check($access);
        DB::transaction(function () use ($r, $agent, $access, $status, $audit) {
            $current = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'active' && ! $current->revoked_at && $current->name === 'teste', 409);
            abort_unless(AgentMaintenanceAccess::whereKey($access->id)->where('revision', $access->revision)
                ->update(['check_status' => $status, 'checked_at' => now()->toIso8601String()]) === 1, 409);
            $audit->record('agent.maintenance_checked', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id]);
        });

        return redirect()->route('agents.maintenance', $agent);
    }

    public function destroy(Request $r, Agent $agent, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        DB::transaction(function () use ($r, $agent, $audit) {
            AgentMaintenanceAccess::where('agent_id', $agent->id)->delete();
            $audit->record('agent.maintenance_removed', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id]);
        });

        return redirect()->route('agents.maintenance', $agent)->with('success', 'Credencial removida do painel. ');
    }
}
