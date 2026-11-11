<?php

namespace App\Telegram;

class Access
{
    public const DENIED = 'Não posso atender esta solicitação porque sua conta ainda não está identificada e autorizada. Vincule seu Telegram no painel do Agent Center e solicite liberação ao administrador da empresa.';

    public function allows(Link $link, ?Group $group = null): bool
    {
        $bot = Bot::find($link->bot_id);
        if (! $bot || ! $bot->enabled || $link->status !== 'ACTIVE') {
            return false;
        }
        if ($group && ($group->bot_id !== $bot->id || $group->status !== 'ACTIVE' || $group->bot_generation !== $bot->generation)) {
            return false;
        }

        return (! $group && ! $bot->restrict_members) || Member::where('bot_id', $bot->id)->where('link_id', $link->id)->where('status', 'ACTIVE')->sharedLock()->first() !== null;
    }
}
