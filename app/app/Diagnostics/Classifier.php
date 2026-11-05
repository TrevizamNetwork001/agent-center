<?php

namespace App\Diagnostics;

class Classifier
{
    public const NORMAL = 'Não foram encontradas anormalidades no escopo testado.';

    public function classify(string $profile, array $evidence, array $thresholds = [], bool $cancelled = false, ?string $executionError = null): array
    {
        $original = $evidence;
        $web = in_array($profile, ['SITE_LENTO', 'SITE_NAO_ABRE', 'GOOGLE_YOUTUBE', 'CONTEUDO_LENTO'], true);
        $primary = $profile === 'PROBLEMA_DNS' ? ['dns_a', 'dns_aaaa'] : ($profile === 'PERDA_LATENCIA' ? ['ping'] : ($web ? ['tcp', 'https'] : []));
        foreach (['dns_a', 'dns_aaaa'] as $key) {
            if (isset($evidence[$key]) && ! in_array($key, $primary, true)) {
                $primary[] = $key;
            }
        }
        $required = $thresholds['required_steps'] ?? $primary;
        $blocked = false;
        $internal = false;
        $failure = false;
        foreach ($evidence as $key => $result) {
            if (! in_array($key, $required, true)) {
                continue;
            }
            $error = $result['error'] ?? $result['data']['error'] ?? null;
            $blocked = $blocked || $error === 'policy_denied';
            $internal = $internal || in_array($error, ['internal_error', 'action_failed', 'execution_timeout', 'lease_lost'], true);
            $failure = $failure || in_array($error, ['dns_failed', 'timeout', 'connection_refused', 'tls_failed', 'unreachable'], true);
        }
        $complete = $required !== [] && collect($required)->every(fn ($key) => ($evidence[$key]['ok'] ?? false) === true);
        $dnsExpected = in_array('dns_a', $required, true) || in_array('dns_aaaa', $required, true);
        $dns = ! $dnsExpected || count($evidence['dns_a']['data']['answers'] ?? []) > 0 || count($evidence['dns_aaaa']['data']['answers'] ?? []) > 0;
        $http = ($evidence['https']['ok'] ?? false) === true ? ($evidence['https']['data'] ?? []) : [];
        $headUnsupported = in_array($http['status'] ?? null, [405, 501], true);
        $tcp = ($evidence['tcp']['ok'] ?? false) === true && ($evidence['tcp']['data']['connected'] ?? false) === true;
        $success = false;
        if ($profile === 'PERDA_LATENCIA') {
            $ping = ($evidence['ping']['ok'] ?? false) === true ? ($evidence['ping']['data'] ?? []) : [];
            if ($ping) {
                $failure = $failure || ($ping['loss_percent'] ?? 0) > ($thresholds['loss_percent'] ?? 10) || ($ping['avg_ms'] ?? 0) > ($thresholds['latency_ms'] ?? 150);
            }
            $success = $complete && $dns && ($ping['received'] ?? 0) > 0 && isset($ping['avg_ms']);
        } elseif ($profile === 'PROBLEMA_DNS') {
            $success = $complete && $dns;
        } elseif ($web) {
            if (($evidence['tcp']['ok'] ?? false) === true && isset($evidence['tcp']['data']['connected']) && ! $tcp) {
                $failure = true;
            }
            if ($http) {
                $failure = $failure || (! $headUnsupported && ($http['status'] ?? 0) >= 400) || ($http['tls'] ?? false) !== true || ($http['duration_ms'] ?? 0) > ($thresholds['slow_ms'] ?? 2000);
            }
            $success = $complete && $dns && $tcp && ($http['status'] ?? 0) >= 200 && ($http['status'] ?? 500) < 400 && ($http['tls'] ?? false) === true;
        }
        $class = $cancelled || $executionError ? 'INCONCLUSIVO' : ($internal ? 'ERRO_DE_EXECUCAO' : ($failure ? 'INDICIO_DE_FALHA' : ($success && ! $blocked ? 'NORMAL' : 'INCONCLUSIVO')));
        $summary = match ($class) {
            'NORMAL' => self::NORMAL,
            'INDICIO_DE_FALHA' => 'Foram encontrados indícios de falha ou lentidão no escopo testado.',
            'ERRO_DE_EXECUCAO' => 'A execução encontrou um erro técnico; revise as etapas afetadas.',
            default => $cancelled ? 'Diagnóstico interrompido; as evidências coletadas foram preservadas.' : match ($executionError) {
                'diagnostic_timeout' => 'O prazo total do diagnóstico foi excedido; as evidências parciais foram preservadas.',
                'agent_unavailable' => 'O Agent não estava disponível para concluir as verificações.',
                'profile_unavailable' => 'O perfil ficou indisponível antes do início das verificações.',
                default => $blocked ? 'Verificações bloqueadas pela política do Agent; evidências insuficientes para concluir no escopo testado.' : ($headUnsupported ? 'O servidor respondeu ao HTTPS, mas não aceita o método HEAD usado neste teste; resultado inconclusivo sobre a abertura da página.' : 'Evidências insuficientes para concluir no escopo testado.'),
            },
        };
        $next = match ($class) {
            'NORMAL' => ['Se o sintoma persistir, amplie o escopo com um novo diagnóstico.'],
            'INDICIO_DE_FALHA' => ['Confira as etapas com falha e repita o teste autorizado para confirmar persistência.'],
            'ERRO_DE_EXECUCAO' => ['Verifique disponibilidade do Agent e os códigos de erro das etapas.'],
            default => $cancelled || $executionError ? ['Confira a disponibilidade do Agent e do perfil antes de iniciar outro diagnóstico.'] : ($blocked ? ['Confira a autorização da capability e a lista local de alvos permitidos no Agent. Após a autorização, repita o diagnóstico.'] : ($headUnsupported ? ['Valide a página por um método aceito pelo servidor; este teste não segue redirecionamentos nem baixa conteúdo.'] : ['Confira as etapas bloqueadas, o alvo e as capabilities disponíveis.'])),
        };
        if ($class === 'NORMAL' && ($http['status'] ?? 0) >= 300) {
            $next[] = 'O servidor retornou um redirecionamento. O destino do redirecionamento não foi testado.';
        }

        return ['classification' => $class, 'summary' => $summary, 'evidence' => $original, 'next_steps' => $next];
    }
}
