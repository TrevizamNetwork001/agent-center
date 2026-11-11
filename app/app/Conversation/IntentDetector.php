<?php

namespace App\Conversation;

use Illuminate\Support\Str;

class IntentDetector
{
    public function detect(string $body, State $state): Intent
    {
        $s = Str::lower(Str::ascii(trim($body)));
        if (preg_match('/\b(cancela|cancelar|pare|parar|interrompa)\b/', $s)) {
            return Intent::Cancel;
        }
        if (preg_match('/\b(era so isso|era isso|resolveu|pode encerrar|nao precisa mais|encerra|encerrar)\b/', $s)) {
            return Intent::Close;
        }
        if (preg_match('/^(como (devo|posso|diagnosticar|investigar|verificar)|qual (e |eh )?((o|a|nosso|nossa) )*(procedimento|guia)|consulte (a )?knowledge|na knowledge|o que diz (a )?knowledge)\b/', $s)) {
            return Intent::Knowledge;
        }
        if (preg_match('/\b(mtr|teste de rota|analisa a perda|perda de pacotes ate|parte do caminho|aumentando a latencia)\b/', $s)) {
            return Intent::Path;
        }
        if (preg_match('/\b(outro teste|outro diagnostico|teste de novo|testa de novo|outro site)\b/', $s)) {
            return Intent::Another;
        }
        if (preg_match('/\b(continua|ainda|persiste|travando|aprofundar)\b/', $s) && in_array($state, [State::Running, State::Completed, State::FollowUp, State::Confirmation], true)) {
            return Intent::Continue;
        }
        if (preg_match('/\b(mais detalhes|detalhe|explique|explica|evidencias)\b/', $s)) {
            return Intent::Detail;
        }
        if (preg_match('/\b(testa|teste|testar|verificar|verifique|diagnostico|conectividade)\b/', $s)) {
            return Intent::Start;
        }
        if (preg_match('/^(sim|confirmo|pode|pode executar|confirmar|fechou|ok)[.! ]*$/', $s)) {
            return Intent::Confirm;
        }
        if (preg_match('/^(nao|negativo|nao quero)[.! ]*$/', $s)) {
            return Intent::Deny;
        }
        if (preg_match('/\b(obrigad[oa]|valeu|agradeco)\b/', $s)) {
            return Intent::Acknowledge;
        }
        if (preg_match('/\b(ajuda|help|como funciona)\b/', $s)) {
            return Intent::Help;
        }
        if ($this->target($body) !== null) {
            return Intent::Target;
        }

        return Intent::Unknown;
    }

    public function target(string $s): ?string
    {
        if (preg_match('/\blocalhost\b/i', $s)) {
            return 'localhost';
        }
        if (preg_match('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $s, $ip) && filter_var($ip[0], FILTER_VALIDATE_IP)) {
            return $ip[0];
        }
        if (preg_match('~(?:https://)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]+)?(?:/[^\s]*)?~i', $s, $m)) {
            return $m[0];
        }
        if (preg_match('/\byoutube\b/i', $s)) {
            return 'youtube.com';
        }
        if (preg_match('/\bgoogle\b/i', $s)) {
            return 'google.com';
        }

        return null;
    }

    public function profile(string $s): string
    {
        $s = Str::lower(Str::ascii($s));

        return match (true) {
            str_contains($s, 'dns') => 'PROBLEMA_DNS',str_contains($s, 'perda') || str_contains($s, 'latencia') => 'PERDA_LATENCIA',str_contains($s, 'nao abre') => 'SITE_NAO_ABRE',str_contains($s, 'conteudo lento') => 'CONTEUDO_LENTO',str_contains($s, 'youtube') || str_contains($s, 'google') => 'GOOGLE_YOUTUBE',default => 'SITE_LENTO'
        };
    }
}
