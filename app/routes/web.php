<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.store');
});
Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/password', [AuthController::class, 'password'])->name('password.edit');
    Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('users', UserController::class)->only(['index', 'store', 'edit', 'update']);
    Route::resource('tenants', TenantController::class)->only(['index', 'store', 'edit', 'update']);
    Route::post('/tenant-context', [TenantController::class, 'select'])->name('tenant.select');
    Route::get('/audit', AuditController::class)->name('audit.index');
});
