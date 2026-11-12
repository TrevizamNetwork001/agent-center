<?php

namespace App\Diagnostics;

use App\Agent\ManagedSettings;
use App\Agent\Protocol;
use App\Models\Agent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Agent-authenticated read-only authorization query; never dispatches an AgentTask. */
class Preflight
{
    public function digest(Agent $agent): string
    {
        return Digest::hash(['policy' => Protocol::policy($agent->id, ($agent->metadata['task_protocol'] ?? null) === 1, ManagedSettings::current($agent)), 'version' => $agent->version, 'capabilities' => Protocol::executionCapabilities($agent)]);
    }

    public function permission(Agent $agent, string $capability, string $destination, string $protocol): array
    {
        $unknown = ['state' => 'UNKNOWN', 'reason' => 'effective_policy_unverified', 'helper_healthy' => null];
        // 0.6.2 cannot answer this contract. Never trust unrelated metadata.
        if ($agent->version !== '0.6.5' || $capability !== 'mtr_path_analysis:v1' || ! filter_var($destination, FILTER_VALIDATE_IP) || ! in_array($protocol, ['icmp', 'tcp'], true)) {
            return [...$unknown, 'reason' => 'preflight_incompatible'];
        }
        $destination = inet_ntop(inet_pton($destination));
        try {
            return DB::transaction(function () use ($agent, $destination, $protocol, $unknown) {
                $a = Agent::whereKey($agent->id)->lock('FOR UPDATE NOWAIT')->firstOrFail();
                if ($a->version !== '0.6.5' || $a->status !== 'active' || $a->revoked_at || ! $a->last_heartbeat_at?->gt(now()->subSeconds(60))) {
                    return [...$unknown, 'reason' => 'agent_unavailable'];
                }
                $meta = $a->metadata ?? [];
                $record = $meta['diagnostic_preflight_v1'] ?? [];
                $digest = $this->digest($a);
                $request = $record['request'] ?? [];
                $matching = ($request['destination'] ?? null) === $destination && ($request['protocol'] ?? null) === $protocol && ($request['policy_digest'] ?? null) === $digest;
                $fresh = ($request['expires_at'] ?? 0) > now()->timestamp;
                if ($matching && $fresh && isset($record['result'], $record['received_at']) && $record['received_at'] > now()->subSeconds(10)->timestamp && ($record['dispatched_at'] ?? 0) > now()->subSeconds(10)->timestamp) {
                    return $record['result'];
                }
                // One bounded slot per Agent. Do not overwrite another user's pending query.
                if ($fresh && ! isset($record['result'])) {
                    return [...$unknown, 'reason' => $matching ? 'preflight_pending' : 'preflight_busy'];
                }
                $reason = $matching && isset($record['result']) ? 'preflight_stale'
                    : (! $fresh && isset($request['id']) && ! isset($record['result']) ? 'preflight_timeout' : 'preflight_pending');
                // A policy change invalidates proof; do not reinterpret it as a denial.
                if (isset($request['policy_digest']) && $request['policy_digest'] !== $digest) {
                    $reason = 'effective_policy_unverified';
                }
                $request = ['schema_version' => 1, 'id' => (string) Str::uuid(), 'destination' => $destination, 'protocol' => $protocol, 'policy_digest' => $digest,
                    'policy_revision' => ManagedSettings::current($a)['revision'], 'expires_at' => now()->addSeconds(45)->timestamp];
                $a->forceFill(['metadata' => [...$meta, 'diagnostic_preflight_v1' => ['request' => $request]]])->save();

                return [...$unknown, 'reason' => $reason];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '55P03') {
                return [...$unknown, 'reason' => 'preflight_busy'];
            }
            throw $e;
        }
    }

    public function exchange(Agent $agent, ?array $result): ?array
    {
        return DB::transaction(function () use ($agent, $result) {
            $a = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($a->version !== '0.6.5') {
                return null;
            }
            $meta = $a->metadata ?? [];
            $record = $meta['diagnostic_preflight_v1'] ?? [];
            $request = $record['request'] ?? null;
            if (! $request || $request['expires_at'] <= now()->timestamp || $request['policy_digest'] !== $this->digest($a)) {
                return null;
            }
            if ($result !== null) {
                if (($record['dispatched_at'] ?? 0) <= now()->subSeconds(5)->timestamp) {
                    return null;
                }
                abort_unless($result['id'] === $request['id'] && $result['policy_digest'] === $request['policy_digest'] && $result['agent_version'] === $a->version, 422);
                abort_unless($result['state'] !== 'ALLOWED' || ($result['helper_healthy'] === true && $result['reason'] === 'authorized' && ($result['helper_version'] ?? null) === '0.6.5' && isset($result['effective_revision'])), 422);
                abort_unless($result['state'] !== 'DENIED' || $result['reason'] === 'policy_denied', 422);
                if (isset($record['result'])) {
                    abort_unless(Digest::hash($record['result']) === Digest::hash($result), 409);
                } else {
                    $record = [...$record, 'result' => $result, 'received_at' => now()->timestamp];
                    $a->forceFill(['metadata' => [...$meta, 'diagnostic_preflight_v1' => $record]])->save();
                }

                return null;
            }

            if (! isset($record['dispatched_at'])) {
                $record['dispatched_at'] = now()->timestamp;
                $a->forceFill(['metadata' => [...$meta, 'diagnostic_preflight_v1' => $record]])->save();
            }

            return isset($record['result']) ? null : $request;
        });
    }
}
