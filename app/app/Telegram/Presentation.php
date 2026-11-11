<?php

namespace App\Telegram;

class Presentation
{
    public static function event(string $event): string
    {
        return match ($event) {
            'telegram.configured' => 'Token da API do bot validado e salvo',
            'telegram.webhook_changed' => 'Recebimento de mensagens atualizado',
            'telegram.link_code_created' => 'Código para vincular a conta criado',
            'telegram.link_claimed' => 'Conta identificada no Telegram; aguardando confirmação no painel',
            'telegram.link_verified' => 'Vínculo da conta confirmado',
            'telegram.link_revoked' => 'Vínculo da conta revogado',
            'telegram.tested' => 'Teste da integração realizado',
            'telegram.processing_failed' => 'Não foi possível processar a mensagem recebida',
            'telegram.delivery_failed' => 'Não foi possível confirmar a entrega da resposta',
            'telegram.delivery_retry' => 'Reenvio da resposta solicitado',
            'telegram.group_code_created' => 'Código para vincular o grupo da empresa criado',
            'telegram.group_claimed' => 'Grupo identificado; aguardando confirmação no painel',
            'telegram.group_verified' => 'Grupo da empresa autorizado',
            'telegram.group_revoked' => 'Autorização do grupo revogada',
            'telegram.member_authorized' => 'Colaborador autorizado a conversar com o bot',
            'telegram.member_revoked' => 'Autorização do colaborador revogada',
            default => 'Evento da integração registrado',
        };
    }
}
