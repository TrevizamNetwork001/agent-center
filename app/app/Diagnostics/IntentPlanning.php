<?php

namespace App\Diagnostics;

use App\Agent\Protocol;
use App\Models\Agent;
use App\Models\Profile;
use App\Models\User;
use App\Noc\Planning;
use Illuminate\Validation\ValidationException;

/** Objective/Profile decisions shared by both channels; no policy mutation. */
class IntentPlanning
{
    public function profile(OperationalIntent $intent, ?Agent $agent, array $request = []): string
    {
        if ($intent->isDevice()) {
            throw ValidationException::withMessages(['profile_id' => Planning::UNAVAILABLE]);
        }

        // A route is the objective; select a supported method, then disclose it
        // before confirmation. This is capability selection, never authorization.
        if ($intent === OperationalIntent::Route && ($request['method'] ?? null) === 'traceroute'
            && ! ($request['strict_method'] ?? false) && ($request['protocol'] ?? 'icmp') !== 'tcp'
            && $agent && in_array($agent->version, Preflight::VERSIONS, true)
            && in_array('mtr_path_analysis:v1', Protocol::executionCapabilities($agent), true)) {
            return 'MTR_ICMP';
        }

        return match ($intent) {
            OperationalIntent::Route => ($request['method'] ?? null) === 'traceroute' ? 'CAMINHO_TRACEROUTE' : (($request['protocol'] ?? null) === 'tcp' ? 'MTR_TCP' : 'MTR_ICMP'),
            OperationalIntent::Connectivity => 'CONECTIVIDADE_BASICA', OperationalIntent::Web => 'SITE_NAO_ABRE',
            OperationalIntent::Dns => 'PROBLEMA_DNS', OperationalIntent::Latency => 'PERDA_LATENCIA',
            OperationalIntent::Port => 'PORTA_SERVICO', OperationalIntent::Slowness => ($request['service'] ?? null) === 'google_youtube' ? 'GOOGLE_YOUTUBE' : (($request['content_slow'] ?? false) ? 'CONTEUDO_LENTO' : 'SITE_LENTO'),
        };
    }

    public function devicePlan(array $request, string $body, User $user): array
    {
        return app(Planning::class)->prepare($request, $body, $user);
    }

    public function context(Profile $profile, array $request = []): array
    {
        $intent = isset($request['operational_intent']) ? OperationalIntent::tryFrom($request['operational_intent']) : OperationalIntent::forProfile($profile->key);
        if (! $intent || $intent !== OperationalIntent::forProfile($profile->key)) {
            throw ValidationException::withMessages(['profile_id' => 'O Profile não cobre o objetivo solicitado. Nenhum teste substituto foi selecionado.']);
        }
        if (($intent === OperationalIntent::Port || $profile->key === 'MTR_TCP') && ($request['port'] ?? 443) !== 443) {
            throw ValidationException::withMessages(['target' => 'O contrato TCP deste Agent suporta somente a porta 443. Nenhuma outra porta será testada como substituta.']);
        }
        if ($profile->key === 'CAMINHO_TRACEROUTE' && ($request['protocol'] ?? 'icmp') !== 'icmp') {
            throw ValidationException::withMessages(['target' => 'A Skill traceroute disponível suporta ICMP. Traceroute TCP não será substituído por ICMP.']);
        }

        return ['operational_intent' => $intent->value, 'intent_planning_version' => 1,
            'protocol' => $profile->key === 'MTR_TCP' ? 'tcp' : ($intent === OperationalIntent::Route ? 'icmp' : null),
            'port' => $intent === OperationalIntent::Port || $profile->key === 'MTR_TCP' ? 443 : null,
            'path_metrics' => $intent === OperationalIntent::Route && ($request['path_metrics'] ?? false) === true];
    }

    public function preview(Profile $profile, Agent $agent, string $target, array $request = []): array
    {
        $context = $this->context($profile, $request);

        return app(Planner::class)->plan($profile, $target, Protocol::executionCapabilities($agent), [...$context, 'agent' => $agent, 'agent_version' => $agent->version, 'dns_resolvers' => $agent->metadata['telemetry']['dns_resolvers'] ?? []]);
    }

    public function confirmation(array $plan): string
    {
        $goal = OperationalIntent::from($plan['objective']['intent']);
        $scope = implode(', ', array_column($plan['steps'], 'logical_group'));
        if ($goal === OperationalIntent::Route) {
            $scope .= match ($plan['objective']['path_method']) {
                'mtr_tcp' => ' (MTR TCP/443)', 'mtr_icmp' => ' (MTR ICMP)', default => ' (traceroute ICMP)',
            };
        }
        $intro = $goal === OperationalIntent::Route ? 'Posso analisar o caminho de rede até ' : 'Posso verificar '.$goal->label().' em ';
        $limit = $plan['profile'] === 'CAMINHO_TRACEROUTE' ? ' Método traceroute ICMP solicitado, sem estatística MTR de perda por salto.' : '';

        return $intro.$plan['target']['host'].'? Escopo: '.$scope.'.'.$limit.' Confirma a execução? A autorização local permanece obrigatória.';
    }

    public function scope(array $plan): string
    {
        $method = match ($plan['objective']['path_method']) {
            'mtr_tcp' => 'MTR TCP/443', 'mtr_icmp' => 'MTR ICMP', default => 'traceroute ICMP',
        };

        return 'Objetivo: '.$plan['objective']['label'].' até '.$plan['target']['host'].'. Profile planejado: '.$plan['profile'].'. Verificação planejada: '.$method.'.';
    }
}
