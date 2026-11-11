<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

if (getenv('TELEGRAM_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Telegram LAB only');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file('/var/www/html/public'.$path)) {
    return false;
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/telegram-transport.php';
$app->handleRequest(Request::capture());
