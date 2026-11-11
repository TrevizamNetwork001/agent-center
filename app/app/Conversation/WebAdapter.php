<?php

namespace App\Conversation;

use Illuminate\Http\Request;

final class WebAdapter implements ChannelAdapter
{
    public function message(Request $request): IncomingMessage
    {
        return new IncomingMessage($this->identity($request), $request->validated('request_key'), $request->validated('body'));
    }

    public function identity(Request $request): Identity
    {
        abort_unless($request->hasSession() && $request->user(), 401);

        return Identity::web($request->user());
    }
}
