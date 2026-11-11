<?php

namespace App\Telegram;

use App\Models\User;

class SetupProgress
{
    public function forUser(User $user, ?Bot $bot): array
    {
        $link = $bot ? Link::where('bot_id', $bot->id)->where('user_id', $user->id)->latest('created_at')->first() : null;
        $valid = $link && ($link->status === 'ACTIVE' || ($link->expires_at->isFuture() && in_array($link->status, ['CODE', 'AWAITING_APPROVAL'], true)));
        $claimed = $valid && in_array($link->status, ['AWAITING_APPROVAL', 'ACTIVE'], true);
        $active = $valid && $link->status === 'ACTIVE';
        $inbound = $active ? Update::where('bot_id', $bot->id)->where('generation', $bot->generation)->where('sender_id', $link->telegram_user_id)->where('created_at', '>=', $link->verified_at)->latest('created_at')->first(['status', 'last_error', 'created_at']) : null;
        $reply = $active ? Outbound::where('link_id', $link->id)->where('generation', $bot->generation)->whereNotNull('message_id')->where('created_at', '>=', $link->verified_at)->latest('created_at')->first(['status', 'last_error', 'created_at']) : null;
        $ready = (bool) ($bot && config('telegram.enabled') && $bot->enabled && $bot->webhook_status === 'REGISTERED' && ! $bot->last_error);
        $done = [(bool) $bot, $ready, (bool) $claimed, (bool) $active, (bool) ($ready && $active && $reply?->status === 'SENT' && ! in_array($inbound?->status, ['PENDING', 'FAILED', 'DENIED'], true))];
        $labels = ['Conectar bot pela API', 'Ativar mensagens', 'Vincular minha conta', 'Confirmar identidade', 'Testar a conversa'];
        $current = 5;
        foreach ($done as $i => $complete) {
            if (! $complete) {
                $current = $i + 1;
                break;
            }
        }
        $steps = [];
        foreach ($labels as $i => $title) {
            $steps[] = ['number' => $i + 1, 'title' => $title, 'done' => $done[$i]];
        }

        return ['steps' => $steps, 'current' => $current, 'completed' => count(array_filter($done)), 'link' => $link, 'validLink' => (bool) $valid, 'linked' => (bool) $active, 'ready' => $ready, 'inbound' => $inbound, 'reply' => $reply, 'authorized' => (bool) ($active && app(Access::class)->allows($link)), 'tested' => $done[4]];
    }
}
