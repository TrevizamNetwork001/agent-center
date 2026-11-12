<?php

namespace App\Conversation;

use App\Llm\Output;

final class Text
{
    public static function clean(string $body): string
    {
        $body = preg_replace('~(https?://)[^\s/@]+(?::[^\s/@]*)?@~iu', '$1[REDACTED]@', $body);
        $body = preg_replace('/\b(?:senha|credencial|chave[_ -]?(?:api|secreta))\s*(?::|=|é)\s*[^\s,;]+/iu', '[REDACTED]', $body);
        $body = preg_replace('/-----BEGIN [^-]*(?:PRIVATE KEY|SECRET)[^-]*-----.*?(?:-----END [^-]+-----|$)/s', '[REDACTED]', $body);
        $body = preg_replace('/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|AKIA[A-Z0-9]{16})\b/', '[REDACTED]', $body);
        $body = preg_replace('/\b[0-9]{5,20}:[A-Za-z0-9_-]{30,100}\b/', '[REDACTED]', $body);

        return Output::clean($body ?? '');
    }
}
