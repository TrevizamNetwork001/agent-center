<?php

namespace App\Telegram;

use App\Conversation\ChannelAdapter;
use App\Conversation\Identity;
use App\Conversation\IncomingMessage;
use Illuminate\Http\Request;

// Only the authenticated ingress/worker may supply a verified Link, never request body fields.
class TelegramAdapter implements ChannelAdapter
{
    public function identity(Request $request): Identity
    {
        $link = $request->attributes->get('telegram_verified_link');
        abort_unless($link instanceof Link, 401);

        return app(Accounts::class)->identity($link);
    }

    public function message(Request $request): IncomingMessage
    {
        return new IncomingMessage($this->identity($request), $request->attributes->get('telegram_request_key'), $request->attributes->get('telegram_text'));
    }
}
