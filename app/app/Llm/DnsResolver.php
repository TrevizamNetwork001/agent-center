<?php

namespace App\Llm;

class DnsResolver
{
    public function resolve(string $host): array
    {
        $rows = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($rows ?: [] as $row) {
            if (isset($row['ip'])) {
                $ips[] = $row['ip'];
            }if (isset($row['ipv6'])) {
                $ips[] = $row['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
