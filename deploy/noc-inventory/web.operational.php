<?php

use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\Agent\BootstrapController;
use App\Http\Controllers\Agent\MaintenanceController;
use App\Http\Controllers\Agent\ManagementController;
use App\Http\Controllers\Agent\ReleaseController;
use App\Http\Controllers\Agent\SettingsController;
use App\Http\Controllers\Agent\UpdateController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticCatalogController;
use App\Http\Controllers\DiagnosticController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LlmController;
use App\Http\Controllers\NocInventoryController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\RagController;
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
    Route::get('/releases', [ReleaseController::class, 'index'])->name('agents.releases.index');
    Route::post('/releases', [ReleaseController::class, 'store'])->name('agents.releases.store');
    Route::get('/', [ManagementController::class, 'index'])->name('agents.index');
    Route::post('/enrollments', [ManagementController::class, 'enrollment'])->name('agents.enrollment');
    Route::get('/{agent}/maintenance', [MaintenanceController::class, 'show'])->whereUuid('agent')->name('agents.maintenance');
    Route::put('/{agent}/maintenance', [MaintenanceController::class, 'store'])->whereUuid('agent')->middleware('throttle:6,1')->name('agents.maintenance.store');
    Route::post('/{agent}/maintenance/check', [MaintenanceController::class, 'check'])->whereUuid('agent')->middleware('throttle:6,1')->name('agents.maintenance.check');
    Route::delete('/{agent}/maintenance', [MaintenanceController::class, 'destroy'])->whereUuid('agent')->name('agents.maintenance.destroy');
    Route::put('/{agent}/settings', [SettingsController::class, 'update'])->whereUuid('agent')->name('agents.settings.update');
    Route::post('/{agent}/operations', [UpdateController::class, 'request'])->whereUuid('agent')->name('agents.operations.request');
    Route::get('/{agent}/repair-updater', [ManagementController::class, 'repairUpdater'])->whereUuid('agent')->name('agents.repair-updater');
    Route::post('/{agent}/operations/{operation}/approve', [UpdateController::class, 'approve'])->whereUuid(['agent', 'operation'])->name('agents.operations.approve');
    Route::post('/{agent}/operations/{operation}/reconcile', [UpdateController::class, 'reconcile'])->whereUuid(['agent', 'operation'])->name('agents.operations.reconcile');
    Route::get('/{agent}', [ManagementController::class, 'show'])->whereUuid('agent')->name('agents.show');
    Route::post('/{agent}/approve', [ManagementController::class, 'approve'])->whereUuid('agent')->name('agents.approve');
    Route::post('/{agent}/revoke', [ManagementController::class, 'revoke'])->whereUuid('agent')->name('agents.revoke');
    Route::delete('/{agent}', [ManagementController::class, 'destroy'])->whereUuid('agent')->name('agents.destroy');
});

Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::get('/diagnostics', [DiagnosticController::class, 'index'])->name('diagnostics.index');
    Route::get('/diagnostics/history', [DiagnosticController::class, 'history'])->name('diagnostics.history');
    Route::get('/profiles', [DiagnosticCatalogController::class, 'profiles'])->name('profiles.index');
    Route::post('/skills/guides/mtr/analysis', [DiagnosticCatalogController::class, 'analyzeMtr'])->middleware('throttle:skill-analysis')->name('skills.mtr.analysis');
    Route::get('/skills/guides/{guide}', [DiagnosticCatalogController::class, 'guide'])->where('guide', 'mtr|tcpdump|nmap')->name('skills.guide');
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
    Route::get('/knowledge/rag', [RagController::class, 'index'])->middleware('throttle:conversation-sync')->name('rag.index');
    Route::post('/knowledge/rag/search', [RagController::class, 'index'])->middleware('throttle:conversation-sync')->name('rag.search');
    Route::post('/knowledge/rag/reindex', [RagController::class, 'reindex'])->middleware('throttle:llm-call')->name('rag.reindex');
    Route::get('/knowledge/sources/{source}', [RagController::class, 'source'])->whereUuid('source')->name('rag.source');
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

require __DIR__.'/backups.php';

Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::get('/agent-requests', [BootstrapController::class, 'index'])->name('agent-requests.index');
    Route::post('/agent-requests/{requestId}/accept', [BootstrapController::class, 'accept'])->whereUuid('requestId')->name('agent-requests.accept');
});

require __DIR__.'/telegram.php';

Route::middleware(['auth', 'auth.session', 'password.changed'])->group(function () {
    Route::get('/account/profile', [AccountProfileController::class, 'edit'])->name('account.edit');
    Route::patch('/account/profile', [AccountProfileController::class, 'update'])->middleware('throttle:10,1')->name('account.update');
    Route::get('/account/avatar', [AccountProfileController::class, 'avatar'])->name('account.avatar');
});

Route::middleware(['auth', 'auth.session', 'password.changed'])->prefix('noc-inventory')->group(function () {
    Route::get('/', [NocInventoryController::class, 'index'])->name('noc.index');
    Route::get('/capabilities', [NocInventoryController::class, 'capabilities'])->name('noc.capabilities');
    Route::get('/create', [NocInventoryController::class, 'create'])->name('noc.create');
    Route::post('/', [NocInventoryController::class, 'store'])->middleware('throttle:30,1')->name('noc.store');
    Route::get('/{nocDevice}', [NocInventoryController::class, 'show'])->whereUuid('nocDevice')->name('noc.show');
    Route::get('/{nocDevice}/edit', [NocInventoryController::class, 'edit'])->whereUuid('nocDevice')->name('noc.edit');
    Route::put('/{nocDevice}', [NocInventoryController::class, 'update'])->whereUuid('nocDevice')->middleware('throttle:30,1')->name('noc.update');
    Route::post('/{nocDevice}/deactivate', [NocInventoryController::class, 'deactivate'])->whereUuid('nocDevice')->middleware('throttle:30,1')->name('noc.deactivate');
    Route::post('/{nocDevice}/simulations', [NocInventoryController::class, 'simulate'])->whereUuid('nocDevice')->middleware('throttle:30,1')->name('noc.simulate');
});
