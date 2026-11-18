<?php

namespace App\Web;

use App\Agent\Protocol;
use App\Diagnostics\Classifier;
use Carbon\CarbonImmutable;

class Presentation
{
    public static function time($date, string $format = 'd/m/Y H:i:s P'): ?string
    {
        if ($date === null) {
            return null;
        }
        $instant = is_string($date) ? CarbonImmutable::parse($date) : CarbonImmutable::instance($date);

        return $instant->setTimezone('America/Sao_Paulo')->format(str_replace(' T', ' P', $format));
    }

    public static function stepStatus($step): string
    {
        $error = $step->result['error'] ?? $step->result['data']['error'] ?? $step->error_code;

        $data = ($step->result['ok'] ?? false) === true ? ($step->result['data'] ?? []) : [];

        return match (true) {
            $error === 'policy_denied' => 'POLICY_BLOCKED',
            in_array($error, ['action_failed', 'internal_error', 'execution_timeout', 'lease_lost', 'diagnostic_timeout', 'agent_unavailable'], true) => 'EXECUTION_ERROR',
            in_array($error, ['dns_failed', 'timeout', 'connection_refused', 'tls_failed', 'unreachable'], true) => 'TECHNICAL_FAILURE',
            ($step->capability ?? null) === 'http_probe:v1' && in_array($data['status'] ?? null, [405, 501], true) => 'LIMITED',
            ($step->capability ?? null) === 'tcp_connect:v1' && ($data['connected'] ?? null) === false => 'TECHNICAL_FAILURE',
            ($step->capability ?? null) === 'http_probe:v1' && (($data['status'] ?? 0) >= 400 || ($data['tls'] ?? null) === false) => 'TECHNICAL_FAILURE',
            ($step->capability ?? null) === 'icmp_ping:v1' && (($data['loss_percent'] ?? 0) > 0 || ($data['received'] ?? null) === 0) => 'TECHNICAL_FAILURE',
            default => $step->status,
        };
    }

    public static function diagnosticError(?string $error): string
    {
        return match ($error) {
            'policy_denied' => 'Bloqueado pela política do Agent',
            'agent_unavailable' => 'Agent indisponível para concluir a verificação',
            'capability_unavailable' => 'Verificação indisponível neste Agent',
            'condition_not_met' => 'Uma etapa anterior não forneceu a evidência necessária',
            'dns_unavailable' => 'Nenhum endereço DNS disponível para esta verificação',
            'dns_failed' => 'Falha na resolução DNS',
            'nodata' => 'A consulta não retornou endereços',
            'connection_refused' => 'A conexão foi recusada pelo destino',
            'tls_failed' => 'A validação TLS falhou',
            'timeout' => 'O destino não respondeu dentro do prazo',
            'unreachable' => 'Não foi possível alcançar o destino',
            'execution_timeout' => 'A verificação excedeu o tempo de execução',
            'diagnostic_timeout' => 'O diagnóstico excedeu o prazo total',
            'lease_lost' => 'A execução perdeu a confirmação com a Central',
            'cancelled' => 'Verificação cancelada',
            'action_failed', 'internal_error' => 'Falha técnica ao executar a verificação',
            default => 'Verificação não concluída',
        };
    }

    public static function conclusion($diagnostic): ?array
    {
        $conclusion = $diagnostic->conclusion;
        if (! $conclusion) {
            return null;
        }
        $evidence = $conclusion->evidence ?? [];
        // Correct legacy display only where no measurement was made; keep append-only records intact.
        if ($conclusion->classification === 'INDICIO_DE_FALHA' && $evidence && collect($evidence)->every(fn ($r) => ($r['error'] ?? $r['data']['error'] ?? null) === 'policy_denied')) {
            return app(Classifier::class)->classify($diagnostic->profile?->key ?? '', $evidence);
        }

        return ['classification' => $conclusion->classification, 'summary' => $conclusion->summary, 'next_steps' => $conclusion->next_steps];
    }

    public static function tone(?string $s): string
    {
        return match ($s) {
            'NORMAL','COMPLETED','ONLINE','VERDE','HEALTHY','active' => 'good','RUNNING','CLAIMED' => 'info','LIMITED','POLICY_BLOCKED','INDICIO_DE_FALHA','INCONCLUSIVO','PENDING','QUEUED','ATENÇÃO' => 'warn','TECHNICAL_FAILURE','EXECUTION_ERROR','FAILED','ERRO_DE_EXECUCAO','EXPIRED','OFFLINE','NO-GO','REVOKED','revoked' => 'bad',default => 'neutral'
        };
    }

    public static function label(?string $s): string
    {
        return match ($s) {
            'platform_admin' => 'Administrador da plataforma', 'tenant_admin' => 'Administrador do tenant', 'operator' => 'Operador', 'viewer' => 'Somente leitura',
            'NORMAL' => 'Normal', 'INDICIO_DE_FALHA' => 'Indício de falha', 'INCONCLUSIVO' => 'Inconclusivo', 'ERRO_DE_EXECUCAO' => 'Erro de execução',
            'VERDE' => 'Operacional', 'ATENÇÃO' => 'Atenção', 'NO-GO' => 'Indisponível', 'HEALTHY' => 'Operacional', 'ONLINE' => 'Online', 'OFFLINE' => 'Offline',
            'active' => 'Habilitado','disabled' => 'Desabilitado','REVOKED' => 'Revogado','COMPLETED' => 'Concluído','RUNNING' => 'Em execução','CLAIMED' => 'Reservado','PENDING' => 'Pendente','QUEUED' => 'Na fila','EXECUTION_ERROR' => 'Erro de execução','TECHNICAL_FAILURE' => 'Teste executado com falha','LIMITED' => 'Verificação limitada','POLICY_BLOCKED' => 'Bloqueado por política','SKIPPED' => 'Dispensado','BLOCKED' => 'Bloqueado','CANCELLED' => 'Cancelado','FAILED' => 'Falhou','EXPIRED' => 'Expirado',null => 'Aguardando conclusão',default => $s
        };
    }

    public static function age($date): string
    {
        return $date ? max(0, (int) $date->diffInSeconds(now())).'s' : 'Sem registro';
    }

    public static function seconds(?int $s): string
    {
        return $s === null ? 'Sem registro' : $s.'s';
    }

    public static function duration($d): string
    {
        return $d->started_at ? round(abs($d->started_at->diffInMilliseconds($d->finished_at ?? now())) / 1000, 1).'s' : '—';
    }

    public static function agentStatus($a): string
    {
        if ($a->revoked_at || $a->status === 'revoked') {
            return 'REVOKED';
        }
        if (in_array($a->status, ['registered', 'pending'], true)) {
            return 'ATENÇÃO';
        }
        if (! $a->last_heartbeat_at || $a->last_heartbeat_at->lte(now()->subSeconds(60))) {
            return 'OFFLINE';
        }

        return ! $a->last_poll_at || $a->last_poll_at->lte(now()->subSeconds(30)) || ($a->metadata['telemetry']['device_operations_frozen'] ?? null) !== true || ! in_array($a->version, Protocol::VERSIONS, true) ? 'ATENÇÃO' : 'ONLINE';
    }

    public static function groupStatus($steps): string
    {
        foreach (['TECHNICAL_FAILURE', 'EXECUTION_ERROR'] as $state) {
            if ($steps->contains(fn ($step) => self::stepStatus($step) === $state)) {
                return $state;
            }
        }
        if ($steps->contains(fn ($step) => self::stepStatus($step) === 'POLICY_BLOCKED') && ! $steps->contains(fn ($step) => in_array($step->status, ['RUNNING', 'CLAIMED', 'QUEUED', 'PENDING']))) {
            return 'POLICY_BLOCKED';
        }
        foreach (['RUNNING', 'CLAIMED', 'QUEUED', 'PENDING', 'FAILED', 'EXPIRED', 'CANCELLED', 'BLOCKED'] as $s) {
            if ($steps->contains('status', $s)) {
                return $s;
            }
        }

        if ($steps->contains(fn ($step) => self::stepStatus($step) === 'LIMITED')) {
            return 'LIMITED';
        }

        return $steps->every(fn ($s) => $s->status === 'SKIPPED') ? 'SKIPPED' : 'COMPLETED';
    }

    public static function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            'agent_info' => 'Informações do agente',
            'icmp_ping:v1' => 'Conectividade por ping',
            'dns_lookup:v1' => 'Consulta DNS',
            'tcp_connect:v1' => 'Conexão TCP',
            'traceroute:v1' => 'Rastreamento de rota',
            'http_probe:v1' => 'Verificação HTTP e HTTPS',
            'mtr_path_analysis:v1' => 'Qualidade da rota MTR',
            default => $capability,
        };
    }

    public static function agentValue($value): string
    {
        if ($value === null) {
            return 'Indisponível';
        }
        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        return match ((string) $value) {
            'AVAILABLE' => 'Disponível',
            'UNAVAILABLE' => 'Indisponível',
            'BLOCKED' => 'Bloqueado',
            'UNKNOWN' => 'Não confirmado',
            'ALLOWED' => 'Permitido',
            'DENIED' => 'Não permitido',
            'STATUS_UNKNOWN' => 'Situação não confirmada',
            'POLICY_BASE_DENIED' => 'Bloqueado pela política local',
            'ADMIN_CEILING_DENIED' => 'Bloqueado pelo limite administrativo',
            'MANAGED_POLICY_DENIED' => 'Bloqueado pela política gerenciada',
            'CAPABILITY_UNAVAILABLE' => 'Recurso indisponível',
            'HELPER_UNAVAILABLE' => 'Componente auxiliar indisponível',
            'HELPER_UNHEALTHY' => 'Componente auxiliar com falha',
            'DEPENDENCY_MISSING' => 'Dependência ausente',
            'PERMISSION_MISSING' => 'Permissão ausente',
            'UNSUPPORTED_VERSION' => 'Versão não compatível',
            'DEVICE_OPERATIONS_FROZEN' => 'Alterações em equipamentos bloqueadas',
            'POLICY_NOT_APPLIED' => 'Política ainda não aplicada',
            'base' => 'Política local',
            'ceiling' => 'Limite administrativo',
            'managed' => 'Política gerenciada',
            default => self::capabilityLabel((string) $value),
        };
    }

    public static function auditSummary($e): string
    {
        $m = $e->metadata ?? [];
        $parts = [];
        if (in_array($m['reason'] ?? '', ['invalid_credentials', 'throttled'], true)) {
            $parts[] = $m['reason'] === 'throttled' ? 'Limite de tentativas atingido' : 'Credenciais não aceitas';
        }
        if (in_array($m['status'] ?? '', ['active', 'disabled'], true)) {
            $parts[] = $m['status'] === 'active' ? 'Conta ativa' : 'Conta desabilitada';
        }
        if (in_array($m['role'] ?? '', ['platform_admin', 'tenant_admin', 'operator', 'viewer'], true)) {
            $parts[] = 'Papel: '.self::label($m['role']);
        }
        if (is_array($m['fields'] ?? null)) {
            $fields = array_intersect($m['fields'], ['name', 'email', 'slug', 'role', 'status']);
            if ($fields) {
                $parts[] = 'Campos: '.implode(', ', array_map(fn ($field) => ['name' => 'Nome', 'email' => 'E-mail', 'slug' => 'Identificador', 'role' => 'Papel', 'status' => 'Situação'][$field], $fields));
            }
        }

        return implode(' · ', $parts) ?: 'Evento registrado; evidência preservada.';
    }
}
