<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

use App\Noc\Analysis\Contracts;
use App\Noc\ReadOnlyLab\Failure;

/** Closed RouterOS 7.16.2 console dialect v1. Never accepts a command or expression. */
final class Protocol
{
    public const VERSION = '7.16.2';

    public const IDENTITY = '/system resource print';

    public function contract(Operation $op): array
    {
        return json_decode(file_get_contents(resource_path('routeros7-protocol-contracts.json')), true, flags: JSON_THROW_ON_ERROR)['contracts'][$op->value];
    }

    public function parameters(Operation $op, array $params): array
    {
        app(Contracts::class)->parameters(app(Contracts::class)->all()[$op->contract()], $params);
        if ($op === Operation::Route) {
            // IPv6 has a typed inventory contract, but is deliberately not executable in v1.
            if (filter_var($params['destination'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new Failure('IPV6_NOT_HOMOLOGATED');
            }
            if (! preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,31}\z/', $params['table'] ?? 'main')) {
                throw new Failure('INVALID_TABLE');
            }
        }

        return $params;
    }

    public function commands(Operation $op, array $params): array
    {
        $params = $this->parameters($op, $params);

        return match ($op) {
            Operation::Identity => ['/system identity print'],
            Operation::Bgp => ['/routing bgp session print terse without-paging', '/routing bgp connection print count-only'],
            Operation::Route => $this->routes($params),
            Operation::Interfaces => ['/interface print terse without-paging'],
            Operation::Counters => ['/interface print stats-detail without-paging'],
            Operation::Resources => [self::IDENTITY],
        };
    }

    private function routes(array $params): array
    {
        // Query only exact ancestor prefixes. Never read the complete routing table.
        $host = unpack('N', inet_pton($params['destination']))[1];
        $commands = [];
        for ($bits = 32; $bits >= 0; $bits--) {
            $mask = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            $prefix = inet_ntop(pack('N', $host & $mask)).'/'.$bits;
            $commands[] = '/routing route print terse without-paging where dst-address='.$prefix.' && routing-table='.($params['table'] ?? 'main');
        }

        return $commands;
    }

    /** Only known fields survive; unknown fields (including secrets) reject the whole response. */
    public function rows(string $text, array $allowed): array
    {
        $this->bounded($text);
        $rows = [];
        foreach (explode("\n", trim(str_replace("\r\n", "\n", $text))) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Flags: ')) {
                continue;
            }
            if (! preg_match('/\A[0-9]{1,4}\s+(?:([A-Za-z*]+)\s+)?([a-z][a-z0-9.-]*=.*)\z/', $line, $match)) {
                throw new Failure('INVALID_RESPONSE');
            }
            $row = ['flags' => trim($match[1] ?? '')];
            $rest = $match[2];
            while ($rest !== '') {
                if (! preg_match('/\A([a-z][a-z0-9.-]*)=("[^"\\\\\x00-\x1f]*"|[^\s"\\\\]+)(?:\s+|\z)/', $rest, $field)) {
                    throw new Failure('INVALID_RESPONSE');
                }
                if (! in_array($field[1], $allowed, true) || array_key_exists($field[1], $row)) {
                    throw new Failure('UNEXPECTED_RESPONSE_FIELD');
                }
                $row[$field[1]] = trim($field[2], '"');
                $rest = substr($rest, strlen($field[0]));
            }
            $rows[] = $row;
            if (count($rows) > 128) {
                throw new Failure('ROW_LIMIT');
            }
        }

        return $rows;
    }

    public function resource(string $text): array
    {
        $this->bounded($text);
        $allowed = ['uptime', 'version', 'build-time', 'factory-software', 'free-memory', 'total-memory', 'cpu', 'cpu-count', 'cpu-frequency', 'cpu-load', 'free-hdd-space', 'total-hdd-space', 'write-sect-since-reboot', 'write-sect-total', 'bad-blocks', 'architecture-name', 'board-name', 'platform'];
        $out = [];
        foreach (explode("\n", trim(str_replace("\r\n", "\n", $text))) as $line) {
            if (! preg_match('/\A\s*([a-z-]+):\s*([^\x00-\x1f]+)\z/', $line, $m) || ! in_array($m[1], $allowed, true) || isset($out[$m[1]])) {
                throw new Failure('INVALID_RESPONSE');
            }
            $out[$m[1]] = trim($m[2]);
        }
        if (($out['version'] ?? null) !== self::VERSION.' (stable)' || ! isset($out['board-name'])) {
            throw new Failure('UNSUPPORTED_ROUTEROS_VERSION');
        }

        return $out;
    }

    private function bounded(string $text): void
    {
        if (strlen($text) > 65536 || ($text !== '' && ! str_ends_with($text, "\n")) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) {
            throw new Failure('INVALID_RESPONSE');
        }
    }

    public function number(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', $value) || (float) $value > 9007199254740991) {
            throw new Failure('INVALID_NUMBER');
        }

        return (int) $value;
    }

    public function duration(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/\A(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?(?:(\d+)ms)?\z/', $value, $m) || $value === '') {
            throw new Failure('INVALID_UPTIME');
        }
        $seconds = 0;
        foreach ([604800, 86400, 3600, 60, 1, .001] as $i => $unit) {
            $seconds += (int) ($m[$i + 1] ?? 0) * $unit;
        }
        if ($seconds > 9007199254740991) {
            throw new Failure('INVALID_UPTIME');
        }

        return $seconds;
    }

    public function bytes(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/\A([0-9]+(?:\.[0-9]+)?)([KMGT]iB|B)?\z/', $value, $m)) {
            throw new Failure('INVALID_MEMORY');
        }
        $bytes = (float) $m[1] * (['B' => 1, 'KiB' => 1024, 'MiB' => 1048576, 'GiB' => 1073741824, 'TiB' => 1099511627776][$m[2] ?? 'B']);
        if ($bytes > 9007199254740991) {
            throw new Failure('INVALID_MEMORY');
        }

        return (int) $bytes;
    }
}
