<?php

namespace App\Diagnostics;

use Illuminate\Validation\ValidationException;

class MtrReport
{
    public function analyze(string $json, string $target): array
    {
        $fail = fn () => throw ValidationException::withMessages(['report' => 'Informe JSON MTR válido, numérico, com até 32 saltos e estatísticas coerentes.']);
        if (! filter_var($target, FILTER_VALIDATE_IP) || strlen($json) > 65536) {
            $fail();
        }
        try {
            $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $fail();
        }
        $hubs = $raw['report']['hubs'] ?? null;
        if (! is_array($hubs) || ! array_is_list($hubs) || ! count($hubs) || count($hubs) > 32) {
            $fail();
        }
        $hops = [];
        $previous = 0;
        foreach ($hubs as $h) {
            if (! is_array($h) || ! is_int($h['count'] ?? null) || $h['count'] < 1 || $h['count'] > 255 || $h['count'] <= $previous) {
                $fail();
            }
            $previous = $h['count'];
            $host = $h['host'] ?? null;
            if (! is_string($host) || ($host !== '???' && ! filter_var($host, FILTER_VALIDATE_IP))) {
                $fail();
            }
            $row = ['hop' => $h['count'], 'ip' => $host === '???' ? null : inet_ntop(inet_pton($host))];
            foreach (['Loss%' => 'loss_percent', 'Snt' => 'sent', 'Avg' => 'avg_ms', 'Best' => 'min_ms', 'Wrst' => 'max_ms', 'StDev' => 'rtt_stddev_ms'] as $key => $out) {
                $v = $h[$key] ?? null;
                if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || $v < 0 || $v > ($key === 'Loss%' ? 100 : 60000)) {
                    $fail();
                }$row[$out] = $v;
            }
            if (! is_int($row['sent']) || $row['sent'] < 1 || $row['sent'] > 10000 || $row['min_ms'] > $row['avg_ms'] || $row['avg_ms'] > $row['max_ms']) {
                $fail();
            }
            $hops[] = $row;
        }
        $protocol = $raw['report']['mtr']['protocol'] ?? '';
        $protocol = is_string($protocol) ? strtolower($protocol) : '';
        if (! in_array($protocol, ['icmp', 'tcp', 'udp'], true)) {
            $protocol = 'não informado';
        }
        $target = inet_ntop(inet_pton($target));
        $last = end($hops);
        $reached = $last['ip'] === $target && $last['loss_percent'] < 100;
        $state = 'INCONCLUSIVO';
        $notes = ['MTR mede respostas às sondas, não TLS, HTTP ou saúde completa da aplicação.', 'Rota de retorno, filtros ICMP e ECMP podem afetar os resultados; um relatório não localiza sozinho a causa.'];
        if (! $reached) {
            $summary = 'O destino não respondeu dentro do trecho observado. Não é possível concluir perda de ponta a ponta com este relatório.';
        } elseif ($last['loss_percent'] > 0) {
            $state = 'INDICIO_DE_FALHA';
            $summary = 'O destino respondeu com perda de respostas na amostra. Confirme persistência e correlacione com o serviço.';
        } else {
            $state = 'SEM_PERDA_OBSERVADA';
            $summary = 'O destino respondeu sem perda de respostas na amostra coletada.';
        }
        if ($reached && $last['loss_percent'] == 0 && collect($hops)->slice(0, -1)->contains(fn ($h) => $h['loss_percent'] > 0)) {
            $notes[] = 'A perda indicada em saltos intermediários não persistiu no destino; não comprova perda do tráfego encaminhado.';
        }
        if ($last['sent'] < 100) {
            $notes[] = 'Amostra curta: '.$last['sent'].' ciclos no último salto. Zero perdas nesta amostra não elimina problemas intermitentes.';
        }
        $notes[] = 'StDev é desvio padrão do RTT; não é uma medição universal de jitter de aplicações.';

        return ['protocol' => $protocol, 'state' => $state, 'summary' => $summary, 'target' => $target, 'reached' => $reached, 'hops' => $hops, 'notes' => $notes];
    }
}
