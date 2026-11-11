<?php

use App\Telegram\Accounts;
use App\Telegram\Bot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Explicit disposable LAB transport only. No production hook or alternate engine.
if (getenv('TELEGRAM_LAB_FIXTURE') !== '1' || DB::connection()->getDatabaseName() !== 'agent_center_telegram_lab') {
    throw new RuntimeException('Telegram disposable LAB only');
}
Http::preventStrayRequests();
Http::fake(['api.telegram.org/*' => function ($r) {
    $bot = Bot::first();

    return Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'agent_center_lab_fixture_bot', 'message_id' => 77, 'url' => $bot ? app(Accounts::class)->webhookUrl($bot) : '', 'pending_update_count' => 0]]);
}]);
