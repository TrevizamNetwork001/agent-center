<?php

namespace App\Llm;

use Symfony\Component\HttpFoundation\IpUtils;

class NetworkPolicy
{
    private const DENIED = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'];

    public function syntax(string $url): array
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (! $p || ($p['scheme'] ?? '') !== 'https' || ! $host || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || ($p['port'] ?? 443) !== 443 || ! str_contains($host, '.') || str_ends_with($host, '.') || preg_match('/^[0-9.]+$/', $host) || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! in_array($host, config('llm.allowed_hosts', []), true) || ! preg_match('#^/[a-zA-Z0-9/_-]*$#', $p['path'] ?? '/')) {
            throw new \RuntimeException('ssrf_blocked');
        }

        return ['host' => $host, 'port' => 443, 'url' => rtrim($url, '/').'/chat/completions'];
    }

    public function endpoint(string $url): array
    {
        $p = $this->syntax($url);
        $ips = app(DnsResolver::class)->resolve($p['host']);
        if (! $ips || count($ips) > 16) {
            throw new \RuntimeException('dns_failure');
        }foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || IpUtils::checkIp($ip, self::DENIED)) {
                throw new \RuntimeException('ssrf_blocked');
            }
        }sort($ips);

        return $p + ['ip' => $ips[0]];
    }
}
