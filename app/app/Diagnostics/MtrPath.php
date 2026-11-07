<?php

namespace App\Diagnostics;

use Illuminate\Validation\ValidationException;

/** Live contract over the existing MTR reader; no external enrichment or causal attribution. */
class MtrPath
{
    public static function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['target', 'destination', 'protocol', 'cycles', 'max_hops', 'interval'], 'properties' => ['target' => ['type' => 'string', 'format' => 'ip'], 'destination' => ['type' => 'string', 'maxLength' => 253], 'protocol' => ['enum' => ['icmp', 'tcp']], 'cycles' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 10], 'max_hops' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 16], 'interval' => ['const' => 1]]];
    }

    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean'], 'error' => ['enum' => Normalizer::ERRORS], 'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['destination', 'resolved_ip', 'protocol', 'cycles', 'total_duration_ms', 'destination_reached', 'hops', 'status', 'error', 'raw_sanitized'], 'properties' => ['destination' => ['type' => 'string', 'maxLength' => 253], 'resolved_ip' => ['type' => 'string', 'format' => 'ip'], 'protocol' => ['enum' => ['icmp', 'tcp']], 'cycles' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 10], 'total_duration_ms' => ['type' => 'number', 'minimum' => 0, 'maximum' => 20000], 'destination_reached' => ['type' => 'boolean'], 'hops' => ['type' => 'array', 'maxItems' => 16], 'status' => ['const' => 'collected'], 'error' => ['const' => null], 'raw_sanitized' => ['type' => 'object']]]]];
    }

    public function validate(array $d): array
    {
        $fail = fn () => throw ValidationException::withMessages(['result' => 'Resultado MTR inválido.']);
        $fields = ['destination', 'resolved_ip', 'protocol', 'cycles', 'total_duration_ms', 'destination_reached', 'hops', 'status', 'error', 'raw_sanitized'];
        if (array_diff($fields, array_keys($d)) || array_diff(array_keys($d), $fields) || ! is_string($d['destination']) || strlen($d['destination']) > 253 || ! is_string($d['resolved_ip']) || ! filter_var($d['resolved_ip'], FILTER_VALIDATE_IP) || ! in_array($d['protocol'], ['icmp', 'tcp'], true) || ! is_int($d['cycles']) || $d['cycles'] < 5 || $d['cycles'] > 10 || ! is_bool($d['destination_reached']) || $d['status'] !== 'collected' || $d['error'] !== null || ! is_array($d['raw_sanitized']) || ! is_array($d['hops']) || ! array_is_list($d['hops']) || ! count($d['hops']) || count($d['hops']) > 16) {
            $fail();
        }
        $duration = $d['total_duration_ms'];
        if ((! is_int($duration) && ! is_float($duration)) || ! is_finite((float) $duration) || $duration < 0 || $duration > 20000) {
            $fail();
        }
        // Reuse the validated numeric reader, strip all unknown raw keys (including source hostname).
        $raw = $d['raw_sanitized'];
        if (array_keys($raw) !== ['report'] || ! is_array($raw['report']) || array_diff(array_keys($raw['report']), ['mtr', 'hubs']) || ($raw['report']['mtr'] ?? null) !== ['protocol' => $d['protocol']]) {
            $fail();
        }
        $read = app(MtrReport::class)->analyze(json_encode($raw, JSON_THROW_ON_ERROR), $d['resolved_ip']);
        if (count($read['hops']) !== count($d['hops']) || $read['reached'] !== $d['destination_reached']) {
            $fail();
        }
        $canonicalRaw = [];
        foreach ($read['hops'] as $i => $h) {
            $row = $d['hops'][$i];
            $responded = $h['ip'] !== null && $h['loss_percent'] < 100;
            $expected = ['hop_index' => $h['hop'], 'address' => $h['ip'], 'hostname' => null, 'sent' => $h['sent'], 'received' => null, 'loss_percent' => $h['loss_percent'], 'avg_ms' => $responded ? $h['avg_ms'] : null, 'best_ms' => $responded ? $h['min_ms'] : null, 'worst_ms' => $responded ? $h['max_ms'] : null, 'stdev_ms' => $responded ? $h['rtt_stddev_ms'] : null, 'responded' => $responded, 'asn' => null];
            if (! is_array($row) || array_diff(array_keys($row), array_keys($expected)) || array_diff(array_keys($expected), array_keys($row)) || $h['hop'] > 16 || $h['sent'] > $d['cycles']) {
                $fail();
            }
            if (! is_int($row['hop_index']) || ! is_int($row['sent'])) {
                $fail();
            }
            $received = $row['received'];
            if ($received !== null && (! is_int($received) || $received < 0 || $received > $h['sent'] || abs($h['loss_percent'] - 100 * ($h['sent'] - $received) / $h['sent']) > 0.11)) {
                $fail();
            }
            $expected['received'] = $received;
            foreach ($expected as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    if ((! is_int($row[$key]) && ! is_float($row[$key])) || $row[$key] != $value) {
                        $fail();
                    }
                } elseif ($row[$key] !== $value) {
                    $fail();
                }
            }
            if (! $responded && ($h['loss_percent'] != 100 || $h['avg_ms'] != 0 || $h['min_ms'] != 0 || $h['max_ms'] != 0 || $h['rtt_stddev_ms'] != 0)) {
                $fail();
            }
            $d['hops'][$i] = $expected;
            $canonicalRaw[] = ['count' => $h['hop'], 'host' => $h['ip'] ?? '???', 'Loss%' => $h['loss_percent'], 'Snt' => $h['sent'], 'Avg' => $h['avg_ms'], 'Best' => $h['min_ms'], 'Wrst' => $h['max_ms'], 'StDev' => $h['rtt_stddev_ms']];
        }
        $d['raw_sanitized'] = ['report' => ['mtr' => ['protocol' => $d['protocol']], 'hubs' => $canonicalRaw]];

        return $d;
    }

    public function analyze(array $data, array $thresholds = []): array
    {
        $d = $this->validate($data);
        $hops = $d['hops'];
        $last = end($hops);
        $minimum = $thresholds['mtr_min_cycles'] ?? 5;
        $sufficient = $last['sent'] >= $minimum;
        $endLoss = $d['destination_reached'] && $last['loss_percent'] > ($thresholds['mtr_loss_percent'] ?? 0);
        $sustained = false;
        $previous = null;
        foreach ($hops as $i => &$h) {
            $h['state'] = ! $h['responded'] ? 'Sem resposta' : 'Resposta observada';
            if ($h['responded'] && $h['loss_percent'] > 0 && ! $endLoss && $i < count($hops) - 1) {
                $h['state'] = 'Perda isolada de respostas';
            }
            if ($endLoss && $h['loss_percent'] > 0) {
                $h['state'] = 'Perda de respostas também no destino';
            }
            if ($previous !== null && $h['responded'] && $h['avg_ms'] - $previous >= ($thresholds['mtr_rtt_increase_ms'] ?? 50) && $h['avg_ms'] >= max(1, $previous) * ($thresholds['mtr_rtt_ratio'] ?? 2)) {
                $following = array_slice($d['hops'], $i + 1);
                $floor = $previous + ($thresholds['mtr_rtt_increase_ms'] ?? 50);
                // Only observed, uninterrupted elevation through a responding destination supports this finding.
                $persistent = $d['destination_reached'] && count($following) >= 1 && collect($following)->every(fn ($n) => $n['responded'] && $n['avg_ms'] >= $floor && $n['sent'] >= $minimum);
                $h['state'] = $persistent ? 'Aumento sustentado de RTT' : 'Pico de RTT sem persistência comprovada';
                $sustained = $sustained || $persistent;
            }
            if ($h['responded']) {
                $previous = $h['avg_ms'];
            }
        }
        unset($h);
        $class = ! $d['destination_reached'] || ! $sufficient ? 'INCONCLUSIVO' : ($endLoss || $sustained ? 'INDICIO_DE_FALHA' : 'NORMAL');
        $summary = match ($class) {
            'NORMAL' => Classifier::NORMAL,
            'INDICIO_DE_FALHA' => 'Há indício de perda de respostas fim a fim ou aumento sustentado de RTT nesta amostra; confirme persistência e correlacione com o serviço.',
            default => ! $sufficient ? 'Amostra insuficiente para concluir.' : 'Destino não confirmado: filtragem, ausência de resposta às sondas e falha de rede não podem ser distinguidas por este MTR.',
        };
        $notes = ['Perda isolada ou hop sem resposta, quando os seguintes respondem, não comprova falha de encaminhamento; pode refletir limitação ou despriorização de respostas.', 'RTT é tempo de ida e volta, não latência unidirecional. StDev é dispersão de RTT, não jitter universal de aplicações.', 'Assimetria, distância, ECMP, MPLS, túneis e mudanças de rota podem afetar a amostra. Protocolos diferentes podem seguir caminhos diferentes.', 'MTR isolado não atribui a causa a um roteador, operadora ou AS. A associação ASN de um IP não comprova o caminho BGP.', 'ASN: Não identificado. Nenhuma consulta externa de enriquecimento foi executada.', 'Amostra curta: '.$last['sent'].' sondas no último salto. Ausência de perda não elimina intermitência.'];

        return ['classification' => $class, 'summary' => $summary, 'hops' => $hops, 'notes' => $notes, 'protocol' => $d['protocol'], 'destination_reached' => $d['destination_reached']];
    }
}
