<?php

namespace App\Conversation;

use Illuminate\Support\Str;

class ConversationToneDetector
{
    public function detect(array $recent): array
    {
        $s = Str::lower(Str::ascii(implode(' ', array_slice($recent, -4))));
        $informal = (bool) preg_match('/\b(mano|valeu|parceiro|fechou|bao|trem|to|e ai)\b/', $s);
        $formal = (bool) preg_match('/\b(poderia|por gentileza|por favor|prezado|verificar a conectividade)\b/', $s);

        return ['formality' => $informal ? 'INFORMAL' : ($formal ? 'FORMAL' : 'NATURAL'), 'verbosity' => preg_match('/\b(detalh|explique|explica)/', $s) ? 'DETAILED' : 'CONCISE', 'technical_level' => preg_match('/\b(dns|tcp|tls|ipv6|lease)\b/', $s) ? 'TECHNICAL' : 'GENERAL', 'directness' => preg_match('/\b(testa|cancela|verifique)\b/', $s) ? 'DIRECT' : 'NEUTRAL', 'regionality' => 'NOT_INFERRED', 'confidence' => ($informal || $formal) ? 0.85 : 0.5];
    }

    public function prefix(string $body, array $tone): string
    {
        if (preg_match('/\bboa noite\b/iu', $body)) {
            return 'Boa noite. ';
        }
        if (preg_match('/\bbão\b/iu', $body)) {
            return 'Bão. ';
        }

        return $tone['formality'] === 'INFORMAL' ? 'Fechou. ' : '';
    }
}
