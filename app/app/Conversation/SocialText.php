<?php

namespace App\Conversation;

use Illuminate\Support\Str;

// Lexical normalization for social recognition only. Never changes stored text,
// destinations, confirmation tokens or Task Engine input.
class SocialText
{
    public function wellbeing(string $body): bool
    {
        $s = Str::lower(Str::ascii($body));
        $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
        $s = preg_replace('/\b(vc|vce|ce)\b/', 'voce', $s);
        $s = preg_replace('/\b(ta+h?|esta+)\b/', 'esta', $s);
        $s = preg_replace('/\b(bao|bom|bemm+)\b/', 'bem', $s);
        $s = preg_replace('/\btd\b/', 'tudo', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $greeting = '(?:(?:oi|ola|opa|e ai|salve|fala|bom dia|boa tarde|boa noite) )?';
        $vocative = '(?:meu rei|meu parceiro|parceiro|amigo|consagrado|calabreso|mano)';
        $question = '(?:(?:como )?(?:voce )?esta (?:tudo )?(?:bem|certo|suave)|como voce esta|como (?:voce )?vai|(?:tudo )?(?:bem|certo|suave)(?: com voce| por ai)?|e voce)';

        return (bool) preg_match('/^'.$greeting.'(?:'.$vocative.' )?'.$question.'(?: '.$vocative.')?(?: (?:kkk+|haha|rsrs))?$/D', $s);
    }
}
