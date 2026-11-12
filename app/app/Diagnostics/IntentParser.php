<?php

namespace App\Diagnostics;

use App\Noc\IntentInterpreter;
use Illuminate\Support\Str;

/** Objective parsing is deterministic and independent of Adaptive Tone and LLM output. */
class IntentParser
{
    public function parse(string $body): array
    {
        $s = preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($body))));
        if ($device = app(IntentInterpreter::class)->parse($body)) {
            return $device;
        }
        $goal = null;
        $ambiguous = false;
        // Explanations and quotations alone do not authorize an operational request.
        if (preg_match('/^(?:o que (?:e|significa)|como funciona|explique o conceito)\b/', $s)) {
            return ['intent' => null, 'ambiguous' => false, 'protocol' => null, 'port' => null];
        }
        $route = (bool) preg_match('/\b(?:mtr|traceroute|tracert|teste de rota|(?:avali(?:ar|a|e)|analis(?:ar|a|e)|ver|veja|verifi(?:car|ca|que)|olh(?:ar|a|e)|chec(?:ar|a)|cheque).{0,45}(?:rota|caminho|trajeto)|como (?:ta|esta|anda).{0,15}(?:rota|caminho)|perda.{0,35}(?:trajeto|caminho|saltos)|parte do caminho|analisa a perda de pacotes ate)\b/', $s);
        $latency = (bool) preg_match('/\b(?:ping|latencia|perda de pacotes|perda no destino)\b/', $s);
        $port = (bool) preg_match('~\b(?:porta|portas|tcp\s*[/ :]\s*\d+)\b~', $s);
        $dns = (bool) preg_match('/\b(?:dns|resolv(?:e|er|endo)|resolucao|registros? (?:a|aaaa))\b/', $s);
        $web = (bool) preg_match('/\b(?:site|pagina|https?|url).{0,35}(?:abr(?:e|ir|indo)|nao abre|disponiv|respond)|\b(?:abr(?:e|ir|indo)|acessar).{0,25}(?:site|pagina)|\bdisponibilidade (?:web|https?)\b/', $s);
        $slow = (bool) preg_match('/\b(?:lent[oa]|lentidao|travando|carregando devagar|demorando)\b/', $s);
        $basic = (bool) preg_match('/\b(?:conectividade|conexao basica|(?:ip|destino|host).{0,20}(?:responde|alcancavel)|(?:alcancar|alcancavel).{0,20}(?:ip|destino))\b/', $s);
        if ($route) {
            $goal = OperationalIntent::Route;
            $ambiguous = (bool) preg_match('/\b(?:ou|talvez)\b.{0,25}(?:ping|site|dns|porta)|\bping\b.{0,15}\bou\b/', $s);
        } elseif ($port) {
            $goal = OperationalIntent::Port;
        } elseif ($dns) {
            $goal = OperationalIntent::Dns;
        } elseif ($latency) {
            $goal = OperationalIntent::Latency;
        } elseif ($slow) {
            $goal = OperationalIntent::Slowness;
        } elseif ($web) {
            $goal = OperationalIntent::Web;
        } elseif ($basic) {
            $goal = OperationalIntent::Connectivity;
        }
        $framedRequest = preg_match('/^(?:dns|mtr|traceroute)\b|\b(?:queria|quero|preciso|pode|posso|avalie|avaliar|analise|analisa|analisar|veja|verifica|verifique|verificar|testa|teste|testar|faz|faca|como|tem perda|o ping|a porta|o site|resolva|resolucao|conectividade)\b/', $s);
        $hasDestination = preg_match('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b|\b[a-z0-9-]+\.[a-z]{2,}\b|\b(?:youtube|google)\b/', $s);
        if (! $framedRequest && (! $hasDestination || preg_match('/^(?:(?:a|minha|nossa) )?rede\b/', $s))) {
            $goal = null;
        }
        $protocol = preg_match('/\btcp\b/', $s) ? 'tcp' : (preg_match('/\bicmp\b/', $s) ? 'icmp' : null);
        $number = preg_match('~\b(?:porta|tcp)\s*[/ :]*\s*(\d{1,5})\b~', $s, $m) ? (int) $m[1] : null;
        $ambiguous = $ambiguous || $goal === null && $hasDestination && (bool) preg_match('/\b(?:avalia[ra]?|verifi(?:ca|car)|veja|test[ae]|testar)\b/', $s) && ! preg_match('/\b(?:site|youtube|google|https)\b/', $s);

        return ['intent' => $goal?->value, 'ambiguous' => $ambiguous, 'protocol' => $protocol, 'port' => $number,
            'method' => preg_match('/\b(?:traceroute|tracert)\b/', $s) ? 'traceroute' : null,
            'service' => preg_match('/\b(?:youtube|google)\b/', $s) ? 'google_youtube' : null,
            'content_slow' => (bool) preg_match('/\bconteudo lento\b/', $s),
            'path_metrics' => $goal === OperationalIntent::Route && (bool) preg_match('/\b(?:perda|latencia|rtt)\b/', $s)];
    }
}
