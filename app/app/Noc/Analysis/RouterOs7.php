<?php

namespace App\Noc\Analysis;

class RouterOs7 implements EvidenceParser
{
    public function normalize(array $c, array $p): array
    {
        $p = $this->keys($p);
        if (in_array($c['kind'], ['bgp', 'interfaces', 'counters'], true)) {
            $key = match ($c['kind']) {
                'bgp' => 'sessions','interfaces' => 'interfaces',default => 'counters'
            };
            if (array_keys($p) !== [$key]) {
                app(Schema::class)->invalid('routeros7_payload');
            }$p = ['items' => $p[$key]];
        }

        return $this->values($p, $c['output_schema']);
    }

    private function keys(array $value, int $depth = 0): array
    {
        if ($depth > 12) {
            app(Schema::class)->invalid('depth');
        }$out = [];
        foreach ($value as $k => $v) {
            $key = is_string($k) ? str_replace('-', '_', $k) : $k;
            if (array_key_exists($key, $out)) {
                app(Schema::class)->invalid('duplicate_field');
            }$out[$key] = is_array($v) ? $this->keys($v, $depth + 1) : $v;
        }

        return $out;
    }

    private function values(mixed $value, array $schema): mixed
    {
        $types = (array) $schema['type'];
        if ($value === null) {
            return null;
        }
        if (in_array('integer', $types, true) && is_string($value) && preg_match('/^(0|[1-9][0-9]{0,15})$/D', $value)) {
            return (int) $value;
        }
        if (in_array('number', $types, true) && is_string($value) && preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,6})?$/D', $value)) {
            return (float) $value;
        }
        if (is_string($value) && isset($schema['enum']) && in_array(strtoupper($value), $schema['enum'], true)) {
            return strtoupper($value);
        }
        if (is_array($value) && in_array('array', $types, true)) {
            return array_map(fn ($v) => $this->values($v, $schema['items']), $value);
        }
        if (is_array($value) && in_array('object', $types, true)) {
            foreach ($value as $k => $v) {
                if (! isset($schema['properties'][$k])) {
                    app(Schema::class)->invalid('unknown_field');
                }$value[$k] = $this->values($v, $schema['properties'][$k]);
            }
        }

        return $value;
    }
}
