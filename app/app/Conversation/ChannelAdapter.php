<?php

namespace App\Conversation;

use Illuminate\Http\Request;

interface ChannelAdapter
{
    public function identity(Request $request): Identity;
}
