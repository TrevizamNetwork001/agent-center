<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentMaintenanceAccess;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpFoundation\IpUtils;

class MaintenanceSsh
{
    // One reviewed read-only command. No command, path, URL or script from the UI.
    private const PRECHECK = "/usr/bin/python3 -I -c 'import json,platform,socket;from pathlib import Path;s=platform.freedesktop_os_release();print(json.dumps({\"hostname\":socket.gethostname().split(\".\")[0],\"os\":s.get(\"ID\"),\"version\":s.get(\"VERSION_ID\"),\"pid1\":Path(\"/proc/1/comm\").read_text().strip()}))'";

    public function targetAllowed(Agent $agent, string $host): bool
    {
        $targets = config('agent_maintenance.targets');

        return $agent->name === 'teste' && $agent->status === 'active' && ! $agent->revoked_at
            && filter_var($host, FILTER_VALIDATE_IP)
            && ! IpUtils::checkIp($host, ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/3', '::/128', '::1/128', '::ffff:0:0/96', 'fe80::/10', 'ff00::/8'])
            && is_array($targets) && is_array($targets[$agent->id] ?? null) && in_array($host, $targets[$agent->id], true);
    }

    private function serverFingerprint(SSH2 $ssh): ?string
    {
        $public = $ssh->getServerPublicHostKey();
        $parts = is_string($public) ? explode(' ', $public) : [];
        $bytes = isset($parts[1]) ? base64_decode($parts[1], true) : false;

        return $bytes === false ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '=');
    }

    public function check(AgentMaintenanceAccess $access): string
    {
        if ($access->expires_at->isPast()) {
            return 'expired';
        }
        $ssh = null;
        try {
            $ssh = new SSH2($access->host, $access->port, 8);
            $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519']]);
            // Verify the independently configured server identity before sending credentials.
            $fingerprint = $this->serverFingerprint($ssh) ?? '';
            if (! hash_equals($access->host_fingerprint, $fingerprint)) {
                return 'host_key_mismatch';
            }
            if (! $ssh->login($access->username, $access->password ?? PublicKeyLoader::loadPrivateKey($access->private_key))) {
                return 'authentication_failed';
            }
            $ssh->setTimeout(8);
            $output = '';
            $ssh->exec(self::PRECHECK, function ($chunk) use (&$output) {
                $output .= substr($chunk, 0, max(0, 2049 - strlen($output)));

                return strlen($output) > 2048;
            });
            if ($ssh->isTimeout() || strlen($output) > 2048 || $ssh->getExitStatus() !== 0) {
                return 'precheck_failed';
            }
            $data = json_decode($output, true);

            return is_array($data) && ($data['hostname'] ?? null) === 'teste' && ($data['os'] ?? null) === 'debian'
                && ($data['version'] ?? null) === '13' && ($data['pid1'] ?? null) === 'systemd' ? 'ready_for_review' : 'wrong_environment';
        } catch (\Throwable) {
            // Never log transport exceptions, remote text, key data or credentials.
            return 'connection_failed';
        } finally {
            $ssh?->disconnect();
        }
    }
}
