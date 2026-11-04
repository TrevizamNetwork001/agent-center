<?php

namespace App\Web;

class Presentation
{
    public static function tone(?string $s): string
    {
        return match ($s) {
            'NORMAL','COMPLETED','ONLINE','VERDE','HEALTHY','active' => 'good','RUNNING','CLAIMED' => 'info','INDICIO_DE_FALHA','INCONCLUSIVO','PENDING','QUEUED','ATENÇÃO' => 'warn','FAILED','ERRO_DE_EXECUCAO','EXPIRED','OFFLINE','NO-GO','REVOKED','revoked' => 'bad',default => 'neutral'
        };
    }

    public static function label(?string $s): string
    {
        return match ($s) {
            'active' => 'Habilitado','disabled' => 'Desabilitado','REVOKED' => 'Revogado','COMPLETED' => 'Concluído','RUNNING' => 'Em execução','CLAIMED' => 'Reservado','PENDING' => 'Pendente','QUEUED' => 'Na fila','SKIPPED' => 'Dispensado','BLOCKED' => 'Bloqueado','CANCELLED' => 'Cancelado','FAILED' => 'Falhou','EXPIRED' => 'Expirado',null => 'Aguardando conclusão',default => $s
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
        return $a->revoked_at || $a->status === 'revoked' ? 'REVOKED' : ($a->last_heartbeat_at && $a->last_heartbeat_at->gt(now()->subSeconds(60)) ? 'ONLINE' : 'OFFLINE');
    }

    public static function groupStatus($steps): string
    {
        foreach (['RUNNING', 'CLAIMED', 'QUEUED', 'PENDING', 'FAILED', 'EXPIRED', 'CANCELLED', 'BLOCKED'] as $s) {
            if ($steps->contains('status', $s)) {
                return $s;
            }
        }

        return $steps->every(fn ($s) => $s->status === 'SKIPPED') ? 'SKIPPED' : 'COMPLETED';
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
            $parts[] = 'Papel: '.$m['role'];
        }
        if (is_array($m['fields'] ?? null)) {
            $fields = array_intersect($m['fields'], ['name', 'email', 'slug', 'role', 'status']);
            if ($fields) {
                $parts[] = 'Campos: '.implode(', ', $fields);
            }
        }

        return implode(' · ', $parts) ?: 'Evento registrado; evidência preservada.';
    }
}
