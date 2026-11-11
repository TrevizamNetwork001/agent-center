<?php

namespace App\Rag;

use App\Llm\Output;
use Illuminate\Support\Str;

class Response
{
    public static function parse(string $text): ?array
    {
        try {
            $v = json_decode($text, true, 12, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($v) || array_diff(array_keys($v), ['answer', 'sources']) || ! is_string($v['answer'] ?? null) || mb_strlen($v['answer']) > 8000 || ! is_array($v['sources'] ?? null) || ! array_is_list($v['sources']) || count($v['sources']) > 8) {
            return null;
        }
        foreach ($v['sources'] as $s) {
            if (! is_string($s) || ! Str::isUuid($s)) {
                return null;
            }
        }
        if ($v['sources'] && trim($v['answer']) === '') {
            return null;
        }

        return ['answer' => Output::clean($v['answer']), 'sources' => array_values(array_unique($v['sources']))];
    }
}
