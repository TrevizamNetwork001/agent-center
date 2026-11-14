<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

use App\Agent\Protocol as AgentProtocol;
use App\Diagnostics\Digest;
use App\Diagnostics\OperationalIntent;
use App\Diagnostics\Preflight;
use App\Models\Agent;
use App\Models\DeviceLabAccess;
use App\Models\Diagnostic;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\NocDevice;
use App\Models\Profile;
use App\Models\User;
use App\Noc\ReadOnlyLab\Failure;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Lab materialization/presentation only; authorization remains Approval/Preflight, execution Engine. */
final class Integration
{
    public const CAPABILITY = 'routeros7_lab:v1';

    public const EXECUTOR = 'PYTHON_LOOPBACK_SIMULATOR_ONLY';

    public static function enabled(): bool
    {
        // Not a configurable feature flag. Both the disposable mount and network are required.
        if (config('app.env') !== 'testing' || is_link('/lab/routeros-agent-lab') || ! is_file('/lab/routeros-agent-lab') || file_get_contents('/lab/routeros-agent-lab') !== "SIMULATED_ROUTEROS_TASK_ENGINE_V1\n") {
            return false;
        }
        $routes = array_slice(file('/proc/net/route', FILE_IGNORE_NEW_LINES) ?: [], 1);
        foreach ($routes as $route) {
            $f = preg_split('/\s+/', trim($route));
            if (($f[1] ?? '') !== '004D12C6' || ($f[7] ?? '') !== 'F0FFFFFF' || ($f[2] ?? '') !== '00000000') {
                return false;
            }
        }

        foreach (file('/proc/net/ipv6_route', FILE_IGNORE_NEW_LINES) ?: [] as $route) {
            $f = preg_split('/\s+/', trim($route));
            if (end($f) !== 'lo') {
                return false;
            }
        }

        return $routes !== [];
    }

    public function input(User $actor, NocDevice $device, Agent $agent, Operation $op, array $parameters): array
    {
        if (! self::enabled() || $device->environment !== 'lab') {
            throw new Failure('DEVICE_FREEZE');
        }
        if ($actor->status !== 'active' || $actor->tenant_id !== $device->tenant_id || $agent->tenant_id !== $device->tenant_id || $agent->tenant_id !== app(TenantContext::class)->tenantId || ! in_array($agent->version, AgentProtocol::VERSIONS, true) || ! in_array(self::CAPABILITY, $agent->capabilities ?? [], true) || ($agent->metadata['task_protocol'] ?? null) !== 1 || ! $agent->last_heartbeat_at?->gt(now()->subMinute())) {
            throw new Failure('DEVICE_PREFLIGHT_UNKNOWN');
        }
        Gate::forUser($actor)->authorize('create', Diagnostic::class);
        $params = app(Protocol::class)->parameters($op, $parameters);
        $record = DeviceLabAccess::where('device_id', $device->id)->first();
        if (! $record || ! is_array($record->protocol_approval)) {
            throw new Failure('DEVICE_PREFLIGHT_UNKNOWN');
        }
        if ($record->state === 'approved_lab' && (strtotime($record->protocol_approval['expires_at'] ?? '') <= now()->timestamp || ($record->protocol_approval['policy_revision'] ?? null) !== (int) config('device_readonly_lab.policy_revision', 1))) {
            throw new Failure('DEVICE_PREFLIGHT_STALE');
        }
        $access = app(Approval::class)->check($actor, $device, $op, $params, self::EXECUTOR);
        $approval = $access->protocol_approval;
        if (! is_string($approval['approval_id'] ?? null) || ! Str::isUuid($approval['approval_id'])) {
            throw new Failure('DEVICE_PREFLIGHT_UNKNOWN');
        }
        if ($approval['agent_id'] !== $agent->id) {
            throw new Failure('AGENT_NOT_AUTHORIZED');
        }
        $binding = ['schema_version' => 1, 'mode' => 'SIMULATED', 'approval_id' => $approval['approval_id'], 'tenant_id' => $device->tenant_id, 'actor_id' => $actor->id,
            'equipment_id' => $device->id, 'device_revision' => $device->revision, 'access_revision' => $access->revision,
            'agent_id' => $agent->id, 'operation' => $op->value, 'parameters_digest' => Digest::hash($params),
            'management_ip' => $device->management_ip, 'destination' => '127.0.0.1', 'port' => 2223,
            'fingerprint' => $approval['host_fingerprint'], 'model' => $device->model, 'os_version' => Protocol::VERSION,
            'policy_revision' => $approval['policy_revision'], 'policy_digest' => app(Preflight::class)->digest($agent),
            'expires_at' => strtotime($approval['expires_at']), 'approved_at' => strtotime($approval['approved_at']), 'device_operations_frozen' => true];

        return ['operation' => $op->value, 'parameters' => $params, 'binding' => $binding, 'binding_digest' => Digest::hash($binding)];
    }

    public function revalidate(Agent $agent, array $input): void
    {
        if (! self::enabled() || array_diff(array_keys($input), ['operation', 'parameters', 'binding', 'binding_digest'])) {
            throw new Failure('DEVICE_PREFLIGHT_DENIED');
        }
        $b = $input['binding'] ?? [];
        if (! is_array($b) || ! is_int($b['actor_id'] ?? null) || ! is_string($b['equipment_id'] ?? null) || ! Str::isUuid($b['equipment_id']) || ! is_string($input['operation'] ?? null) || ! is_array($input['parameters'] ?? null) || ! is_string($input['binding_digest'] ?? null)) {
            throw new Failure('DEVICE_PREFLIGHT_DENIED');
        }
        $actor = User::findOrFail($b['actor_id'] ?? 0);
        $device = NocDevice::whereKey($b['equipment_id'] ?? '')->lockForUpdate()->firstOrFail();
        $context = app(TenantContext::class);
        $previous = [$context->tenantId, $context->userId, $context->platform];
        try {
            // Re-evaluate the approved human's current native Gates in the same tenant.
            // Agent API authentication supplies no user context of its own.
            $context->apply($previous[0], $actor->id);
            $current = $this->input($actor, $device, $agent->fresh(), Operation::from($input['operation']), $input['parameters']);
        } finally {
            $context->apply(...$previous);
        }
        if (! hash_equals(Digest::hash($current), Digest::hash($input))) {
            throw new Failure('DEVICE_PREFLIGHT_STALE');
        }
    }

    public function enqueue(User $actor, Agent $agent, array $input): Diagnostic
    {
        return DB::transaction(function () use ($actor, $agent, $input) {
            // Same Agent-first ordering as claim, before approval/credential row locks and FKs.
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            NocDevice::whereKey($input['binding']['equipment_id'])->lockForUpdate()->firstOrFail();
            $decision = app(Preflight::class)->device($actor, NocDevice::findOrFail($input['binding']['equipment_id']), $agent, Operation::from($input['operation']), $input['parameters']);
            if ($decision['state'] !== 'ALLOWED' || Digest::hash($decision['input']) !== Digest::hash($input)) {
                throw new Failure('DEVICE_PREFLIGHT_STALE');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?),hashtext(?))', [(string) $agent->tenant_id, $input['binding_digest']]);
            $previous = DiagnosticStep::where('definition->input->binding_digest', $input['binding_digest'])->first();
            if ($previous) {
                return Diagnostic::findOrFail($previous->diagnostic_id);
            }
            $p = Profile::where('key', 'ROUTEROS7_SIMULATED')->first();
            if (! $p) {
                $p = (new Profile)->forceFill(['key' => 'ROUTEROS7_SIMULATED', 'tenant_id' => $agent->tenant_id, 'id' => (string) Str::uuid(), 'display_name' => 'RouterOS 7 · LABORATÓRIO / SIMULADO', 'description' => 'SSH simulado pelo Python Agent; freeze operacional ativo.', 'enabled' => true, 'version' => 1, 'definition' => []]);
                $p->save();
            }
            if (! $p->enabled) {
                throw new Failure('DEVICE_PROFILE_DISABLED');
            }
            $d = (new Diagnostic)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id, 'profile_id' => $p->id, 'created_by' => $actor->id,
                'target' => 'LABORATÓRIO / SIMULADO · '.$input['operation'], 'context' => ['routeros_lab' => true, 'operational_intent' => $this->intent(Operation::from($input['operation']))->value], 'status' => 'RUNNING', 'started_at' => now()]);
            $d->save();
            $step = ['key' => 'routeros_lab', 'skill' => 'routeros7_simulated', 'skill_version' => 1, 'logical_group' => 'RouterOS · LAB / SIMULADO', 'capability' => self::CAPABILITY,
                'input' => $input, 'timeout' => 8, 'wave' => 1, 'optional' => false, 'condition' => 'always', 'available' => true];
            $doc = ['schema_version' => 1, 'profile' => $p->key, 'target' => ['literal' => true, 'host' => '127.0.0.1'], 'steps' => [$step], 'thresholds' => ['required_steps' => ['routeros_lab']], 'objective' => ['intent' => $d->context['operational_intent'], 'required_steps' => ['routeros_lab']], 'mode' => 'SIMULATED'];
            (new DiagnosticPlan)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'version' => 1, 'digest' => Digest::hash($doc), 'document' => $doc, 'created_at' => now()])->save();
            (new DiagnosticStep)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'key' => $step['key'], 'logical_group' => $step['logical_group'], 'capability' => self::CAPABILITY, 'wave' => 1, 'optional' => false, 'status' => 'PENDING', 'definition' => $step])->save();
            app(Audit::class)->record('diagnostic.routeros_lab_confirmed', $actor, $d->tenant_id, 'diagnostic', metadata: ['diagnostic_id' => $d->id, 'binding_digest' => $input['binding_digest'], 'device_id' => $input['binding']['equipment_id'], 'agent_id' => $agent->id, 'contract_id' => $input['operation'], 'mode' => 'SIMULATED']);

            return $d; // Existing Engine::tick materializes the single task and owns its entire lifecycle.
        });
    }

    public function intent(Operation $op): OperationalIntent
    {
        return match ($op) {
            Operation::Identity => OperationalIntent::DeviceIdentity, Operation::Bgp => OperationalIntent::DeviceBgp, Operation::Route => OperationalIntent::DeviceRouting,
            Operation::Interfaces => OperationalIntent::DeviceInterfaces, Operation::Counters => OperationalIntent::DeviceCounters, Operation::Resources => OperationalIntent::DeviceResources,
        };
    }

    public function summary(array $evidence): string
    {
        $data = $evidence['data'] ?? [];
        $text = match ($evidence['contract_id'] ?? '') {
            Operation::Identity->value => 'Identidade: '.($data['name'] ?? 'indisponível').'; RouterOS '.($data['version'] ?? 'indisponível').'.',
            Operation::Bgp->value => 'Peers BGP: '.count($data['items'] ?? []).' observados; '.count(array_filter($data['items'] ?? [], fn ($r) => $r['state'] === 'ESTABLISHED')).' estabelecidos.',
            Operation::Route->value => ($data['found'] ?? false) ? 'Prefixo ativo correspondente observado na RIB.' : 'Nenhuma rota ativa correspondente observada.',
            Operation::Interfaces->value, Operation::Counters->value => 'Interfaces: '.count($data['items'] ?? []).' observadas. Contadores acumulados não comprovam taxas atuais.',
            Operation::Resources->value => 'CPU: '.($data['cpu_percent'] ?? 'indisponível').'%; memória: '.($data['memory_percent'] ?? 'indisponível').'%.',
            default => 'Consulta não produziu evidências válidas.',
        };

        return 'LABORATÓRIO / SIMULADO: '.$text.' Não são medições do equipamento físico; compatibilidade com hardware não verificada.';
    }
}
