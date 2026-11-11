<?php

use App\Http\Controllers\Agent\BootstrapController;
use App\Http\Controllers\Agent\ProtocolController;
use App\Http\Controllers\Agent\ReleaseController;
use App\Http\Controllers\Agent\UpdateController;
use App\Http\Controllers\TelegramController;
use App\Http\Middleware\AgentOriginLimit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/agent')->middleware(AgentOriginLimit::class)->group(function () {
    Route::post('bootstrap/request', [BootstrapController::class, 'request'])->middleware('throttle:agent-enrollment');
    Route::post('bootstrap/status', [BootstrapController::class, 'status'])->middleware('throttle:agent-bootstrap-status');
    Route::post('enroll', [ProtocolController::class, 'enroll'])->middleware('throttle:agent-enrollment')->name('agent.enroll');
    Route::post('enrollment-status', [ProtocolController::class, 'enrollmentStatus'])->middleware('throttle:agent-enrollment')->name('agent.enrollment-status');
    Route::middleware(['agent.auth', 'throttle:agent-api'])->group(function () {
        Route::post('operations/claim', [UpdateController::class, 'claim']);
        Route::post('operations/report', [UpdateController::class, 'report']);
        Route::get('operations/{operation}/artifact', [ReleaseController::class, 'artifact'])->whereUuid('operation');
        foreach (['heartbeat', 'capabilities', 'claim', 'reconcile', 'start', 'renew', 'result'] as $method) {
            Route::post($method, [ProtocolController::class, $method])->name('agent.'.$method);
        }

    });
});

Route::post('/telegram/{tenant}/{bot}', [TelegramController::class, 'webhook'])->whereNumber('tenant')->whereUuid('bot')->middleware('throttle:telegram-ingress')->name('telegram.webhook');
