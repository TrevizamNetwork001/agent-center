<?php

use App\Http\Controllers\Agent\ManagementController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticCatalogController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LlmController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PromptController;
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
    Route::get('/diagnostics/history', [DiagnosticController::class, 'history'])->name('diagnostics.history');
    Route::get('/profiles', [DiagnosticCatalogController::class, 'profiles'])->name('profiles.index');
    Route::get('/skills', [DiagnosticCatalogController::class, 'skills'])->name('skills.index');
    Route::get('/profiles/{profile}', [DiagnosticCatalogController::class, 'show'])->whereUuid('profile')->name('profiles.show');
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

Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('/knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
    Route::post('/knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    Route::get('/knowledge/{knowledge}', [KnowledgeController::class, 'show'])->whereUuid('knowledge')->name('knowledge.show');
    Route::post('/knowledge/{knowledge}/draft', [KnowledgeController::class, 'draft'])->whereUuid('knowledge')->name('knowledge.draft');
    foreach (['publish', 'archive'] as $op) {
        Route::post('/knowledge/{knowledge}/'.$op, [KnowledgeController::class, $op])->whereUuid('knowledge')->name('knowledge.'.$op);
    }
    Route::post('/knowledge/{knowledge}/rollback/{version}', [KnowledgeController::class, 'rollback'])->whereUuid(['knowledge', 'version'])->name('knowledge.rollback');
    Route::get('/advanced/prompts', [PromptController::class, 'index'])->name('prompts.index');
    Route::get('/advanced/prompts/create', [PromptController::class, 'create'])->name('prompts.create');
    Route::post('/advanced/prompts', [PromptController::class, 'store'])->name('prompts.store');
    Route::get('/advanced/prompts/{prompt}', [PromptController::class, 'show'])->whereUuid('prompt')->name('prompts.show');
    foreach (['draft', 'publish', 'archive'] as $op) {
        Route::post('/advanced/prompts/{prompt}/'.$op, [PromptController::class, $op])->whereUuid('prompt')->name('prompts.'.$op);
    }
    Route::post('/advanced/prompts/{prompt}/preview/{version}', [PromptController::class, 'preview'])->whereUuid(['prompt', 'version'])->name('prompts.preview');
    Route::get('/advanced/llm', [LlmController::class, 'index'])->name('llm.index');
    Route::post('/advanced/llm/providers', [LlmController::class, 'providerStore'])->name('llm.provider.store');
    Route::put('/advanced/llm/providers/{provider}', [LlmController::class, 'providerUpdate'])->whereUuid('provider')->name('llm.provider.update');
    Route::post('/advanced/llm/models', [LlmController::class, 'modelStore'])->name('llm.model.store');
    Route::post('/advanced/llm/bindings', [LlmController::class, 'binding'])->name('llm.binding');
    Route::post('/advanced/llm/models/{model}/defaults', [LlmController::class, 'defaults'])->whereUuid('model')->name('llm.defaults');
    Route::post('/advanced/llm/providers/{provider}/test', [LlmController::class, 'test'])->whereUuid('provider')->middleware('throttle:llm-call')->name('llm.test');
    Route::post('/diagnostics/{diagnostic}/interpret', [LlmController::class, 'interpret'])->whereUuid('diagnostic')->middleware('throttle:llm-call')->name('diagnostics.interpret');
});

Route::middleware(['auth', 'auth.session', 'password.changed'])->prefix('conversations')->group(function () {
    Route::get('/', [ConversationController::class, 'index'])->name('conversations.index');
    Route::post('/', [ConversationController::class, 'store'])->middleware('throttle:conversation-message')->name('conversations.store');
    Route::put('/settings', [ConversationController::class, 'settings'])->name('conversations.settings');
    Route::get('/{conversation}', [ConversationController::class, 'show'])->whereUuid('conversation')->name('conversations.show');
    Route::post('/{conversation}/messages', [ConversationController::class, 'send'])->whereUuid('conversation')->middleware('throttle:conversation-message')->name('conversations.send');
    Route::post('/{conversation}/sync', [ConversationController::class, 'sync'])->whereUuid('conversation')->middleware('throttle:conversation-sync')->name('conversations.sync');
    Route::post('/{conversation}/interpret', [ConversationController::class, 'interpret'])->whereUuid('conversation')->middleware('throttle:llm-call')->name('conversations.interpret');
});
