<?php

namespace App\Llm;

class Output
{
    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'explanation', 'next_steps'], 'properties' => ['summary' => ['type' => 'string', 'maxLength' => 2000], 'explanation' => ['type' => 'string', 'maxLength' => 8000], 'next_steps' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'string', 'maxLength' => 500]]]];
    }

    public static function parse(string $text): ?array
    {
        try {
            $v = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }if (! is_array($v) || array_diff(array_keys($v), ['summary', 'explanation', 'next_steps']) || ! is_string($v['summary'] ?? null) || ! is_string($v['explanation'] ?? null) || ! is_array($v['next_steps'] ?? null) || ! array_is_list($v['next_steps']) || count($v['next_steps']) > 6) {
            return null;
        }
        foreach (['summary' => 2000, 'explanation' => 8000] as $key => $limit) {
            if (mb_strlen($v[$key]) > $limit || trim($v[$key]) === '') {
                return null;
            }
        }foreach ($v['next_steps'] as $s) {
            if (! is_string($s) || mb_strlen($s) > 500) {
                return null;
            }
        }

        return ['summary' => self::clean($v['summary']), 'explanation' => self::clean($v['explanation']), 'next_steps' => array_map(self::clean(...), $v['next_steps'])];
    }

    public static function clean(string $v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        $v = preg_replace('/(?:Bearer\s+[A-Za-z0-9_.\-]{8,}|\bsk-[A-Za-z0-9_\-]{8,}|\b(?:api[_ -]?key|password|secret|token)\s*[:=]\s*[^\s,;]+)/i', '[REDACTED]', $v);

        return $v ?? '';
    }
}
