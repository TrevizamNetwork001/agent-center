<?php

namespace App\Agent;

use Illuminate\Support\Facades\DB;

class AgentContext
{
    public static function clear(): void
    {
        DB::select("SELECT set_config('app.agent_id','',false),set_config('app.agent_token_hash','',false),set_config('app.enrollment_id','',false),set_config('app.enrollment_hash','',false)");
    }
}
