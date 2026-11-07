<?php

namespace App\Agent;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

class ManagedSettings
{
    public static function ip(?string $value): ?string
    {
        return $value && filter_var($value, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($value)) : null;
    }

    public static function defaults(Agent $agent): array
    {
        $ip = self::ip($agent->last_seen_ip);

        return ['revision' => 1, 'source_ips' => $ip ? [$ip] : [], 'auto_public_targets' => true, 'allowed_networks' => [], 'allowed_dns_names' => []];
    }

    public static function current(Agent $agent): array
    {
        return $agent->metadata['managed_settings'] ?? self::defaults($agent);
    }

    public function allow(Agent $agent, ?string $source): bool
    {
        return DB::transaction(function () use ($agent, $source) {
            $a = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $meta = $a->metadata ?? [];
            $settings = self::current($a);
            $enforced = ($meta['source_acl_enforced'] ?? false) || version_compare($a->version, '0.6.0', '>=');
            $ip = self::ip($source);
            if ($enforced && (! $ip || ! in_array($ip, $settings['source_ips'], true))) {
                if ($ip && ($meta['pending_source_ip'] ?? null) !== $ip) {
                    $a->forceFill(['metadata' => [...$meta, 'pending_source_ip' => $ip]])->save();
                }

                return false;
            }
            if (! isset($meta['managed_settings'])) {
                // Only seed from the already observed enrollment address; never trust an unapproved new origin.
                if ($settings['source_ips'] === [] && $ip && ! $enforced) {
                    $settings['source_ips'] = [$ip];
                }
                $meta['managed_settings'] = $settings;
                $a->forceFill(['metadata' => $meta])->save();
            }

            return true;
        });
    }
}
