<?php

namespace App\Diagnostics;

class Digest
{
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR));
    }

    private static function canonical(array $v): array
    {
        if (! array_is_list($v)) {
            ksort($v);
        }foreach ($v as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $v;
    }
}
