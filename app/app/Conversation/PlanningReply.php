<?php

namespace App\Conversation;

/** Presentation only: never decides authorization or changes an execution plan. */
class PlanningReply
{
    public function blocked(string $target, string $reason): string
    {
        $explanation = match ($reason) {
            'policy_denied' => 'A política atual não permite analisar a rota até '.$target.'.',
            'preflight_pending' => 'A consulta de autorização para analisar a rota até '.$target.' ainda está pendente. Peça novamente em instantes.',
            'preflight_stale' => 'A verificação anterior dessa rota venceu. Solicitei uma nova consulta; peça novamente em instantes.',
            'preflight_timeout' => 'Não recebi a resposta de autorização no prazo. Tente novamente em instantes.',
            'preflight_busy' => 'Há outra consulta em andamento. Tente analisar essa rota novamente em instantes.',
            'helper_unavailable', 'helper_unverified', 'capability_unavailable' => 'O recurso de análise de rota está indisponível ou ainda não pôde ser verificado.',
            'agent_unavailable' => 'O Agent não está respondendo agora. Tente novamente em instantes.',
            'preflight_incompatible' => 'Esta versão do Agent ainda não permite verificar essa autorização.',
            'preflight_method_unsupported' => 'O tracert/traceroute ainda não tem verificação de autorização disponível neste Agent. O contrato atual cobre MTR. Se quiser esse outro método, peça "faça um MTR para '.$target.'"; a autorização e a confirmação continuarão obrigatórias.',
            default => 'Ainda não consegui verificar se essa análise de rota está autorizada. Isso não significa que ela foi negada.',
        };

        return $explanation.' Nenhum teste foi executado.';
    }

    public function confirmation(string $target, string $method = 'mtr_icmp'): string
    {
        $label = match ($method) {
            'mtr_tcp' => 'MTR TCP/443', 'traceroute_icmp' => 'traceroute ICMP', default => 'MTR ICMP',
        };

        return 'Posso analisar o caminho de rede até '.$target.'? Vou usar '.$label.'. Nenhum teste foi executado ainda. Confirma a execução?';
    }
}
