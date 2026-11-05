<?php

namespace App\Conversation;

use Illuminate\Http\Request;

final class WebAdapter implements ChannelAdapter
{
    public function identity(Request $request): Identity
    {
        abort_unless($request->hasSession() && $request->user(), 401);

        return Identity::web($request->user());
    }
}
