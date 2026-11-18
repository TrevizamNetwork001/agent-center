<?php

namespace App\Conversation;

use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\OperationalIntent;
use Illuminate\Support\Str;

class IntentDetector
{
    public function detect(string $body, State $state): Intent
    {
        $s = Str::lower(Str::ascii(trim($body)));
        if (preg_match('/^(nao[, ]+)?(?:nao quero|nao confirmo|nao pode|nao execute)\b/', $s)) {
            return Intent::Deny;
        }
        if (preg_match('/^(?:tchau|ate mais|ate logo|falou)[.! ]*$/', $s)) {
            return Intent::Close;
        }
        if (preg_match('/^(?:(?:valeu|obrigad[oa]|certo|beleza)[,.! ]+)?(?:pode (?:fechar|finalizar)(?: (?:o|a) (?:chat|conversa|atendimento))?|vamos encerrar)[.! ]*$/', $s)) {
            return Intent::Close;
        }
        if (preg_match('/\b(cancela|cancelar|pare|parar|interrompa)\b/', $s)) {
            return Intent::Cancel;
        }
        if (preg_match('/\b(era so isso|era isso|resolveu|pode encerrar|nao precisa mais|encerra|encerrar)\b/', $s)) {
            return Intent::Close;
        }
        if (preg_match('/^(mudando de assunto|vamos falar de outra coisa|deixa (isso|pra la)|outro assunto)\b/', $s)) {
            return Intent::Topic;
        }
        if (preg_match('/\b(knowledge|base de conhecimento)\b/', $s)) {
            return Intent::Knowledge;
        }
        if (preg_match('/^(como (devo|posso|diagnosticar|investigar|verificar)|qual (e |eh )?((o|a|nosso|nossa) )*(procedimento|guia)|consulte (a )?knowledge|na knowledge|o que diz (a )?knowledge)\b/', $s)) {
            return Intent::Knowledge;
        }
        if ($state === State::Confirmation && preg_match('/^(nao|negativo)\b/', $s)) {
            return Intent::Deny;
        }
        // Recognize replies before diagnostic keywords, including outside the pending
        // state so a late/repeated affirmative cannot become a new request.
        if (preg_match('/^(?:(?:isso|sim)[,! ]+)?pode fazer[.!? ]*$/', $s)) {
            return Intent::Confirm;
        }
        if (preg_match('/^(nao|negativo|nao quero)(?:[,.! ]+(?:confirmo|pode executar|o diagnostico))?[.! ]*$/', $s)) {
            return Intent::Deny;
        }
        if (preg_match('~^(?:(?:sim[, ]+)?(?:confirmo|confirmar|pode executar|pode verificar)|sim|pode|fechou|ok)(?: (?:o |a )?(?:diagnostico|verificacao|teste|coleta mtr))?(?: (?:de|para|em|no alvo) (?:https://)?[a-z0-9][a-z0-9.:/\[\]-]*)?[.! ]*$~', $s)) {
            return Intent::Confirm;
        }
        if (preg_match('/\b(teste de rota|analisa a perda|perda de pacotes ate|parte do caminho|aumentando a latencia)\b/', $s)) {
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
        $operational = app(IntentParser::class)->parse($body);
        if ($operational['intent'] === OperationalIntent::Route->value) {
            return Intent::Path;
        }
        if ($operational['intent'] !== null || $operational['ambiguous']) {
            return Intent::Start;
        }
        if (preg_match('/\b(testa|teste|testar|verificar|verifique|diagnostico|conectividade)\b/', $s)) {
            return Intent::Start;
        }
        if (preg_match('/\b(obrigad[oa]|valeu|agradeco)\b/', $s)) {
            return Intent::Acknowledge;
        }
        if (preg_match('/\b(ajuda|help|como funciona)\b/', $s)) {
            return Intent::Help;
        }
        if (app(ConversationToneDetector::class)->preference($body) !== null) {
            return Intent::Social;
        }
        if (preg_match('/\b(bng|pppoe|radius|bgp|olt|onu|rede|carroca|perda de pacotes|fora do ar|sem conexao|incidente)\b/', $s)) {
            return Intent::Social;
        }
        if (app(SocialText::class)->wellbeing($body)) {
            return Intent::Social;
        }
        if (preg_match('/^(bom dia|boa tarde|boa noite|ola|oi|e ai|salve|fala)\b/', $s)) {
            return Intent::Greeting;
        }
        if (preg_match('/\b(tudo (bem|certo)|como (vai|voce esta)|e voce|kkkk*|haha|rsrs|piada|brincadeira)\b/', $s)) {
            return Intent::Social;
        }
        if ($this->target($body) !== null) {
            return Intent::Target;
        }

        return Intent::Unknown;
    }

    public function confirmationTarget(string $body): ?string
    {
        // Extract the entire explicit destination, including IPv6, rather than
        // guessing a host from another word in the affirmative reply.
        return preg_match('/\b(?:de|para|em|no alvo)\s+(\S+)[.! ]*$/i', trim($body), $match)
            ? rtrim($match[1], '.!') : null;
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
        $request = app(IntentParser::class)->parse($s);
        if ($request['intent'] && $request['intent'] !== OperationalIntent::Slowness->value) {
            return app(IntentPlanning::class)->profile(OperationalIntent::from($request['intent']), null, $request);
        }
        $s = Str::lower(Str::ascii($s));

        return match (true) {
            str_contains($s, 'dns') => 'PROBLEMA_DNS',str_contains($s, 'perda') || str_contains($s, 'latencia') => 'PERDA_LATENCIA',str_contains($s, 'nao abre') => 'SITE_NAO_ABRE',str_contains($s, 'conteudo lento') => 'CONTEUDO_LENTO',str_contains($s, 'youtube') || str_contains($s, 'google') => 'GOOGLE_YOUTUBE',default => 'SITE_LENTO'
        };
    }
}
