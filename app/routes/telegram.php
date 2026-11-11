<?php

use App\Http\Controllers\TelegramCompanyController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'password.changed'])->prefix('integrations/telegram')->name('telegram.')->group(function () {
    Route::get('/', [TelegramController::class, 'index'])->name('index');
    Route::middleware('throttle:telegram-admin')->group(function () {
        Route::post('/groups/code', [TelegramCompanyController::class, 'issue'])->name('groups.issue');
        Route::post('/groups/{group}/approve', [TelegramCompanyController::class, 'approve'])->whereUuid('group')->name('groups.approve');
        Route::post('/groups/{group}/revoke', [TelegramCompanyController::class, 'revoke'])->whereUuid('group')->name('groups.revoke');
        Route::post('/members', [TelegramCompanyController::class, 'allow'])->name('members.allow');
        Route::post('/members/{member}/revoke', [TelegramCompanyController::class, 'deny'])->whereUuid('member')->name('members.revoke');
        Route::post('/bot', [TelegramController::class, 'save'])->name('save');
        Route::post('/webhook', [TelegramController::class, 'register'])->name('register');
        Route::post('/test', [TelegramController::class, 'test'])->name('test');
        Route::post('/link', [TelegramController::class, 'issue'])->name('issue');
        Route::post('/links/{link}/approve', [TelegramController::class, 'approve'])->whereUuid('link')->name('approve');
        Route::post('/links/{link}/revoke', [TelegramController::class, 'revoke'])->whereUuid('link')->name('revoke');
        Route::post('/messages/{outbound}/retry', [TelegramController::class, 'retry'])->whereUuid('outbound')->name('retry');
    });
});
