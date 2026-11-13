<?php

namespace App\Noc\Analysis;

/** Deterministic facts only. No universal threshold and no inferred root cause. */
class Interpretation
{
    public function assess(array $c, array $e): array
    {
        $facts = [];
        $limitations = ['SIMULATED / LAB: dados fictícios, sem coleta de um equipamento real.', 'Não altera saúde, alertas, autorização ou diagnóstico operacional.'];
        $state = 'NORMAL';
        $data = $e['data'];
        if ($e['status'] === 'UNAVAILABLE') {
            return $this->finish('UNAVAILABLE', 'Equipamento indisponível apenas no cenário de laboratório.', [], [...$limitations, 'Indisponibilidade do cenário não comprova queda de equipamento real.'], 'NONE');
        }
        if (! $e['complete']) {
            $state = 'INCONCLUSIVE';
            $limitations[] = 'Evidências parciais; ausência de dados não comprova normalidade nem falha.';
        }
        switch ($c['kind']) {
            case 'identity':$facts[] = $this->fact('identity', 'Plataforma declarada '.$e['identity']['platform_family'].', modelo '.$e['identity']['model'].', versão '.$e['identity']['os_version'].'.');
                break;
            case 'bgp':
                $n = count($data['items']);
                $est = count(array_filter($data['items'], fn ($p) => $p['state'] === 'ESTABLISHED'));
                $outside = $n - $est;
                if (! $n) {
                    $state = 'INCONCLUSIVE';
                } elseif (count(array_filter($data['items'], fn ($p) => ! in_array($p['state'], ['ESTABLISHED', 'UNKNOWN'], true))) > 0) {
                    $state = 'ATTENTION';
                }
                $facts[] = $this->fact('bgp_summary', 'O equipamento simulado possui '.$n.' peer(s): '.$est.' estabelecido(s) e '.$outside.' fora de Established.');
                foreach ($data['items'] as $p) {
                    $text = 'Peer '.$p['peer'].': '.$p['state'].'. Uptime: '.($p['uptime_seconds'] === null ? 'não disponível' : $p['uptime_seconds'].' s').'.';
                    $drops = 0;
                    foreach ($p['transitions'] as $t) {
                        if ($t['from'] === 'ESTABLISHED' && $t['to'] !== 'ESTABLISHED') {
                            $drops++;
                        }if ($t['reason'] !== null && $t['reason'] !== 'UNKNOWN') {
                            $text .= ' Motivo registrado no evento: '.$t['reason'].'.';
                        }
                    }
                    if ($drops) {
                        $state = 'ATTENTION';
                        $text .= ' Foram registradas '.$drops.' saída(s) de Established no histórico fornecido.';
                    }
                    if ($p['state'] === 'UNKNOWN' && $state === 'NORMAL') {
                        $state = 'INCONCLUSIVE';
                    }
                    $facts[] = $this->fact('bgp_peer', $text);
                }
                $limitations[] = 'Estado de sessão e motivo registrado não identificam, isoladamente, a causa raiz.';
                break;
            case 'route':
                if (! $data['found']) {
                    $state = $e['complete'] ? 'ATTENTION' : 'INCONCLUSIVE';
                    $facts[] = $this->fact('route_absent', 'Nenhuma rota correspondente a '.$data['destination'].' foi encontrada na tabela '.$data['table'].' do cenário.');
                }
                foreach ($data['items'] as $r) {
                    $facts[] = $this->fact('route', 'Tabela '.$data['table'].': '.$r['prefix'].' via '.($r['next_hop'] ?? 'não informado').', protocolo '.$r['protocol'].', preferência '.($r['preference'] ?? 'não informada').', métrica '.($r['metric'] ?? 'não informada').'.');
                }
                $limitations[] = 'Uma tabela consultada não representa outras VRFs, a FIB ou o caminho MTR da VM; ausência de rota não prova indisponibilidade geral.';
                break;
            case 'interfaces':
                if (! $data['items']) {
                    $state = 'INCONCLUSIVE';
                }
                foreach ($data['items'] as $r) {
                    if ($r['admin_state'] === 'UP' && $r['oper_state'] === 'DOWN') {
                        $state = 'ATTENTION';
                    } elseif (in_array('UNKNOWN', [$r['admin_state'], $r['oper_state']], true) && $state === 'NORMAL') {
                        $state = 'INCONCLUSIVE';
                    }
                    $facts[] = $this->fact('interface', $r['interface'].': administrativo '.$r['admin_state'].', operacional '.$r['oper_state'].', velocidade '.($r['speed_bps'] === null ? 'não informada' : $r['speed_bps'].' bit/s').'. Flaps acumulados: '.($r['flaps_total'] ?? 'não informados').'.');
                }
                if (isset($data['items'][0]['rx_errors'])) {
                    $limitations[] = 'Bytes e erros são contadores acumulados de uma única amostra. Sem janela temporal não indicam crescimento nem falha.';
                }
                $limitations[] = 'Porta administrativamente desativada não é classificada como falha. Flaps acumulados sem janela não indicam crescimento.';
                break;
            case 'counters':
                if (! $data['items']) {
                    $state = 'INCONCLUSIVE';
                }
                foreach ($data['items'] as $r) {
                    $valid = $r['sample_seconds'] !== null && $r['sample_seconds'] > 0 && $r['baseline_boot_id'] !== null && $r['boot_id'] === $r['baseline_boot_id'];
                    $deltas = [];
                    foreach (['rx_errors', 'tx_errors', 'crc_errors', 'link_downs'] as $k) {
                        if ($r['baseline_'.$k] === null || $r[$k] < $r['baseline_'.$k]) {
                            $valid = false;
                        } else {
                            $deltas[$k] = $r[$k] - $r['baseline_'.$k];
                        }
                    }
                    if (! $valid) {
                        if ($state !== 'ATTENTION') {
                            $state = 'INCONCLUSIVE';
                        }$text = $r['interface'].': contadores acumulados, sem comparação temporal válida ou com reinício/reset. Não é possível afirmar crescimento.';
                    } else {
                        $growth = array_sum($deltas) > 0;
                        if ($growth) {
                            $state = 'ATTENTION';
                        }$text = $r['interface'].': variação em '.$r['sample_seconds'].' s: RX '.$deltas['rx_errors'].', TX '.$deltas['tx_errors'].', CRC '.$deltas['crc_errors'].', quedas de link '.$deltas['link_downs'].'.';
                    }
                    $facts[] = $this->fact('counters', $text);
                }
                $limitations[] = 'Valor acumulado diferente de zero não é falha; crescimento exige duas amostras comparáveis, intervalo e mesmo ciclo de contadores.';
                break;
            case 'resources':
                $cpu = $data['cpu_percent'];
                $mem = $data['memory_percent'] ?? ($data['memory_total_bytes'] !== null && $data['memory_total_bytes'] > 0 && $data['memory_used_bytes'] !== null ? round(100 * $data['memory_used_bytes'] / $data['memory_total_bytes'], 2) : null);
                foreach (['cpu_percent' => [$cpu, 'CPU'], 'memory_percent' => [$mem, 'Memória']] as $key => [$value,$label]) {
                    $ref = $data['references'][$key];
                    $text = $label.': '.($value === null ? 'sem dados' : $value.'%').'. Referência '.($ref === null ? 'não configurada' : $ref.'%').' ('.$data['references']['source'].').';
                    if ($value === null || $ref === null) {
                        if ($state !== 'ATTENTION') {
                            $state = 'INCONCLUSIVE';
                        }
                    } elseif ($value > $ref) {
                        $state = 'ATTENTION';
                    }$facts[] = $this->fact('resource', $text);
                }
                $limitations[] = 'Referências são configuradas para o laboratório, não limites universais. Uma amostra não comprova saturação persistente nem causa raiz.';
                break;
            case 'alarms':
                $state = 'INCONCLUSIVE';
                foreach ($data['items'] as $a) {
                    $facts[] = $this->fact('alarm', 'Registro de alarme '.$a['code'].', severidade '.$a['severity'].', documentação correspondente '.($a['documented'] ? 'declarada' : 'não confirmada').'.');
                }
                $limitations[] = 'Alarmes exigem correspondência com o catálogo oficial da release; códigos desconhecidos não recebem causa inventada.';
                break;
        }
        $summary = match ($state) {
            'ATTENTION' => 'Há uma condição que merece atenção no cenário de laboratório.','INCONCLUSIVE' => 'Os dados do cenário não permitem uma conclusão completa.',default => 'As verificações descritas apresentam resultado normal somente nesta simulação.'
        };

        return $this->finish($state, $summary, $facts, $limitations, $e['complete'] ? 'FULL' : 'PARTIAL');
    }

    private function fact(string $key, string $text): array
    {
        return ['key' => $key, 'text' => $text, 'plain' => $text];
    }

    private function finish(string $state, string $summary, array $facts, array $limitations, string $coverage): array
    {
        return ['classification' => $state, 'summary' => $summary, 'facts' => $facts, 'limitations' => $limitations, 'coverage' => ['status' => $coverage, 'scope' => 'SIMULATED / LAB', 'operational' => 'NOT_EXECUTED'], 'root_cause' => null];
    }
}
