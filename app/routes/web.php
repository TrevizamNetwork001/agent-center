<?php

use App\Http\Controllers\Agent\ManagementController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticCatalogController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\OperationsController;
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

Route::middleware(['auth', 'auth.session', 'password.changed'])->prefix('agents')->group(function () {
    Route::get('/', [ManagementController::class, 'index'])->name('agents.index');
    Route::post('/enrollments', [ManagementController::class, 'enrollment'])->name('agents.enrollment');
    Route::get('/{agent}', [ManagementController::class, 'show'])->whereUuid('agent')->name('agents.show');
    Route::post('/{agent}/revoke', [ManagementController::class, 'revoke'])->whereUuid('agent')->name('agents.revoke');
});

Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::get('/diagnostics', [DiagnosticController::class, 'index'])->name('diagnostics.index');
    Route::get('/diagnostics/create', [DiagnosticController::class, 'create'])->name('diagnostics.create');
    Route::post('/diagnostics', [DiagnosticController::class, 'store'])->name('diagnostics.store');
    Route::get('/diagnostics/{diagnostic}', [DiagnosticController::class, 'show'])->whereUuid('diagnostic')->name('diagnostics.show');
    Route::post('/diagnostics/{diagnostic}/cancel', [DiagnosticController::class, 'cancel'])->whereUuid('diagnostic')->name('diagnostics.cancel');
    Route::get('/diagnostic-catalog', [DiagnosticCatalogController::class, 'index'])->name('diagnostic-catalog.index');
    Route::put('/diagnostic-catalog/profiles/{profile}', [DiagnosticCatalogController::class, 'profile'])->whereUuid('profile')->name('diagnostic-catalog.profile');
    Route::put('/diagnostic-catalog/skills/{skill}', [DiagnosticCatalogController::class, 'skill'])->whereUuid('skill')->name('diagnostic-catalog.skill');
    Route::post('/diagnostic-catalog/profiles/{profile}/fixtures', [DiagnosticCatalogController::class, 'fixtures'])->whereUuid('profile')->name('diagnostic-catalog.fixtures');
    Route::get('/operations', [OperationsController::class, '__invoke'])->name('operations');
});
