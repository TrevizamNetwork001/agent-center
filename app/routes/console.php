<?php

use App\Conversation\IdleSweep;
use App\Telegram\Sweep;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram:drain {tenant}', function () {
    $tenant = (string) $this->argument('tenant');
    if (! ctype_digit($tenant) || (int) $tenant < 1) {
        $this->error('Tenant inválido.');

        return 1;
    }
    app(Sweep::class)->run((int) $tenant);
    $this->info('Telegram: recuperação e acompanhamento enfileirados.');
});
foreach (config('telegram.tenants', []) as $tenant) {
    Schedule::command('telegram:drain '.(int) $tenant)->everyMinute()->withoutOverlapping();
}

Artisan::command('conversations:close-idle {tenant}', function () {
    $tenant = (string) $this->argument('tenant');
    if (! ctype_digit($tenant) || (int) $tenant < 1) {
        $this->error('Tenant inválido.');

        return 1;
    }
    $count = app(IdleSweep::class)->run((int) $tenant);
    $this->info('Conversas encerradas por inatividade: '.$count.'.');
});
if (config('conversation.idle_enabled')) {
    foreach (config('conversation.idle_tenants', []) as $tenant) {
        Schedule::command('conversations:close-idle '.(int) $tenant)->everyMinute()->withoutOverlapping();
    }
}
