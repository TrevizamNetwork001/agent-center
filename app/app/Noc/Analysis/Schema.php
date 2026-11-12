<?php

namespace App\Noc\Analysis;

use Illuminate\Validation\ValidationException;

/** Bounded JSON-schema subset used by all specialist inputs/outputs. No coercion here. */
class Schema
{
    public function check(mixed $value, array $schema, string $path = 'evidence', int $depth = 0): void
    {
        if ($depth > 16) {
            $this->invalid($path);
        }
        $types = (array) ($schema['type'] ?? []);
        $type = $value === null ? 'null' : (is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : (is_float($value) ? 'number' : (is_string($value) ? 'string' : (is_array($value) ? (array_is_list($value) && $value !== [] ? 'array' : 'object') : 'invalid')))));
        $valid = in_array($type, $types, true) || ($type === 'integer' && in_array('number', $types, true)) || ($value === [] && (in_array('array', $types, true) || in_array('object', $types, true)));
        if (! $valid) {
            $this->invalid($path);
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->invalid($path);
        }
        if ($value === null) {
            return;
        }
        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value) || $value < ($schema['minimum'] ?? -INF) || $value > ($schema['maximum'] ?? INF)) {
                $this->invalid($path);
            }
        }
        if (is_string($value)) {
            if (strlen($value) > ($schema['maxLength'] ?? 256) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $this->invalid($path);
            }
            if (isset($schema['pattern']) && ! preg_match('~'.$schema['pattern'].'~D', $value)) {
                $this->invalid($path);
            }
            if (($schema['format'] ?? null) === 'ip' && ! filter_var($value, FILTER_VALIDATE_IP)) {
                $this->invalid($path);
            }
            if (($schema['format'] ?? null) === 'cidr') {
                $parts = explode('/', $value);
                $max = isset($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
                if (count($parts) !== 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP) || ! ctype_digit($parts[1]) || (int) $parts[1] > $max) {
                    $this->invalid($path);
                }
            }
        }
        if (in_array('array', $types, true) && is_array($value)) {
            if (! array_is_list($value) || count($value) > ($schema['maxItems'] ?? 128)) {
                $this->invalid($path);
            }
            foreach ($value as $i => $v) {
                $this->check($v, $schema['items'], $path.'.'.$i, $depth + 1);
            }
        } elseif (in_array('object', $types, true) && is_array($value)) {
            if (array_diff(array_keys($value), array_keys($schema['properties'] ?? [])) || array_diff($schema['required'] ?? [], array_keys($value))) {
                $this->invalid($path);
            }
            foreach ($value as $k => $v) {
                $this->check($v, $schema['properties'][$k], $path.'.'.$k, $depth + 1);
            }
        }
    }

    public function invalid(string $path = 'evidence'): never
    {
        throw ValidationException::withMessages(['analysis' => 'Estrutura de análise inválida ou fora dos limites ('.$path.').']);
    }
}
