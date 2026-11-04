<?php

namespace App\Diagnostics;

class Classifier
{
    public const NORMAL = 'Não foram encontradas anormalidades no escopo testado.';

    public function classify(string $profile, array $evidence, array $thresholds = [], bool $cancelled = false): array
    {
        $class = 'INCONCLUSIVO';
        $primary = $profile === 'PROBLEMA_DNS' ? ['dns_a', 'dns_aaaa'] : ($profile === 'PERDA_LATENCIA' ? ['ping'] : ['dns_a', 'dns_aaaa', 'tcp', 'https']);
        $internal = false;
        $failure = false;
        foreach ($evidence as $k => $r) {
            if (! in_array($k, $primary, true)) {
                continue;
            }$err = $r['error'] ?? $r['data']['error'] ?? null;
            if (in_array($err, ['internal_error', 'action_failed', 'execution_timeout', 'lease_lost'], true)) {
                $internal = true;
            }
            if (in_array($err, ['dns_failed', 'timeout', 'connection_refused', 'tls_failed', 'unreachable'], true)) {
                $failure = true;
            }
        }
        $dns = ! isset($evidence['dns_a']) && ! isset($evidence['dns_aaaa']) || count($evidence['dns_a']['data']['answers'] ?? []) > 0 || count($evidence['dns_aaaa']['data']['answers'] ?? []) > 0;
        $tcp = ($evidence['tcp']['data']['connected'] ?? false) === true;
        $http = $evidence['https']['data'] ?? [];
        if (isset($evidence['tcp']['data']) && ! $tcp) {
            $failure = true;
        }
        if ($http && (($http['status'] ?? 500) >= 400 || ($http['tls'] ?? false) !== true || ($http['duration_ms'] ?? 0) > ($thresholds['slow_ms'] ?? 2000))) {
            $failure = true;
        }
        if ($profile === 'PROBLEMA_DNS' && isset($evidence['dns_a'],$evidence['dns_aaaa']) && ! $dns) {
            $failure = true;
        }
        if ($profile === 'PERDA_LATENCIA') {
            $p = $evidence['ping']['data'] ?? [];
            if ($p && (($p['loss_percent'] ?? 100) > ($thresholds['loss_percent'] ?? 10) || ($p['avg_ms'] ?? 0) > ($thresholds['latency_ms'] ?? 150))) {
                $failure = true;
            }$success = $p && ($p['received'] ?? 0) > 0;
        } elseif ($profile === 'PROBLEMA_DNS') {
            $success = isset($evidence['dns_a'],$evidence['dns_aaaa']) && $dns;
        } else {
            $success = $dns && $tcp && $http && ($http['status'] ?? 500) < 400 && ($http['tls'] ?? false) === true;
        }
        if (! $cancelled) {
            $class = $internal ? 'ERRO_DE_EXECUCAO' : ($failure ? 'INDICIO_DE_FALHA' : ($success ? 'NORMAL' : 'INCONCLUSIVO'));
        }
        $summary = match ($class) {
            'NORMAL' => self::NORMAL,'INDICIO_DE_FALHA' => 'Foram encontrados indícios de falha ou lentidão no escopo testado.','ERRO_DE_EXECUCAO' => 'A execução encontrou um erro técnico; revise as etapas afetadas.',default => $cancelled ? 'Diagnóstico interrompido; as evidências coletadas foram preservadas.' : 'Evidências insuficientes para concluir no escopo testado.'
        };

        return ['classification' => $class, 'summary' => $summary, 'evidence' => $evidence, 'next_steps' => match ($class) {
            'NORMAL' => ['Se o sintoma persistir, amplie o escopo com um novo diagnóstico.'],'INDICIO_DE_FALHA' => ['Confira as etapas com falha e repita o teste autorizado para confirmar persistência.'],'ERRO_DE_EXECUCAO' => ['Verifique disponibilidade do Agent e os códigos de erro das etapas.'],default => ['Confira as etapas bloqueadas, o alvo e as capabilities disponíveis.']
        }];
    }
}
