<?php

use App\Http\Controllers\Agent\ProtocolController;
use App\Http\Middleware\AgentOriginLimit;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/agent')->middleware(AgentOriginLimit::class)->group(function () {
    Route::post('enroll', [ProtocolController::class, 'enroll'])->middleware('throttle:agent-enrollment')->name('agent.enroll');
    Route::middleware(['agent.auth', 'throttle:agent-api'])->group(function () {
        foreach (['heartbeat', 'capabilities', 'claim', 'reconcile'] as $method) {
            Route::post($method, [ProtocolController::class, $method])->name('agent.'.$method);
        }
        foreach (['start', 'renew', 'result'] as $method) {
            Route::post($method, [ProtocolController::class, 'unavailable'])->name('agent.'.$method);
        }
    });
});
