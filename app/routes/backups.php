<?php

use App\Http\Controllers\BackupSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'password.changed', 'can:manage-platform-backups'])->group(function () {
    Route::get('/admin/backups', [BackupSettingsController::class, 'index'])->name('backups.settings');
    Route::put('/admin/backups/r2', [BackupSettingsController::class, 'update'])->middleware('throttle:platform-backups')->name('backups.update');
    Route::post('/admin/backups/request', [BackupSettingsController::class, 'request'])->middleware('throttle:platform-backups')->name('backups.request');
});
