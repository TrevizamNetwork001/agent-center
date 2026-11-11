<?php

namespace App\Conversation;

use Illuminate\Support\Str;

class ConversationToneDetector
{
    public function detect(array $recent, ?string $preference = null): array
    {
        $messages = array_map(fn ($s) => Str::lower(Str::ascii($s)), array_slice($recent, -4));
        $latest = end($messages) ?: '';
        $style = 'CASUAL';
        // Recent explicit signals outweigh earlier slang; neutral replies keep continuity.
        foreach ($messages as $s) {
            $style = match (true) {
                (bool) preg_match('/\b(bng|pppoe|radius|bgp|olt|onu|dns|tcp|tls|ipv6|lease)\b/', $s) => 'TECHNICAL',
                (bool) preg_match('/\b(poderia|por gentileza|por favor|prezado|verificar a conectividade)\b/', $s) => 'FORMAL',
                (bool) preg_match('/\b(mano|valeu|parceiro|consagrado|meu rei|calabreso|salve|fechou|bao|trem|carroca|to|e ai|ce|vc|suave|kkkk*)\b/', $s) => 'RELAXED',
                default => $style,
            };
        }
        if (in_array($preference, ['FORMAL', 'TECHNICAL', 'CASUAL', 'RELAXED'], true)) {
            $style = $preference;
        }
        $serious = (bool) preg_match('/\b(incidente|indisponibilidade|fora do ar|sem (conexao|servico)|queda geral|perda de pacotes|clientes sem|urgente|grave)\b/', implode(' ', $messages)) && ! preg_match('/\b(resolvido|normalizou)\b/', $latest);
        if ($serious) {
            $style = 'TECHNICAL';
        }

        return ['style' => $style, 'severity' => $serious ? 'SERIOUS' : 'ROUTINE', 'formality' => $style === 'RELAXED' ? 'INFORMAL' : ($style === 'FORMAL' ? 'FORMAL' : 'NATURAL'), 'verbosity' => preg_match('/\b(detalh|explique|explica)/', $latest) ? 'DETAILED' : 'CONCISE', 'technical_level' => $style === 'TECHNICAL' ? 'TECHNICAL' : 'GENERAL', 'directness' => preg_match('/\b(testa|cancela|verifique)\b/', $latest) ? 'DIRECT' : 'NEUTRAL', 'regionality' => 'NOT_INFERRED', 'confidence' => $style === 'CASUAL' ? 0.5 : 0.85];
    }

    public function preference(string $body): ?string
    {
        $s = Str::lower(Str::ascii(trim($body)));
        if (! preg_match('/^(?:prefiro (?:um tom |respostas )?|pode falar (?:mais |de forma )?|fale (?:de forma )?|responda (?:de forma )?)(formal|tecnico|casual|descontraido|automatico)[.!? ]*$/', $s, $match)) {
            return null;
        }

        return ['formal' => 'FORMAL', 'tecnico' => 'TECHNICAL', 'casual' => 'CASUAL', 'descontraido' => 'RELAXED', 'automatico' => 'AUTO'][$match[1]];
    }

    public function prefix(string $body, array $tone): string
    {
        if (($tone['severity'] ?? '') === 'SERIOUS' || ($tone['style'] ?? '') === 'TECHNICAL') {
            return '';
        }
        if (preg_match('/\bboa noite\b/iu', $body)) {
            return 'Boa noite. ';
        }
        if (preg_match('/\bbão\b/iu', $body)) {
            return 'Bão. ';
        }

        return $tone['formality'] === 'INFORMAL' ? 'Fechou. ' : '';
    }
}
