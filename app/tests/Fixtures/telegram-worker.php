<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/telegram-transport.php';
Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--timeout' => 90]);
echo "LAB queue drained (Telegram transport fixture).\n";
require __DIR__.'/telegram-results.php';
