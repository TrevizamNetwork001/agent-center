<?php

use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::group([], base_path('routes/health.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ResolveTenant::class]);
        $middleware->appendToPriorityList(StartSession::class, ResolveTenant::class);
        $middleware->alias(['password.changed' => RequirePasswordChange::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): void {
            // QueryException pode conter bindings sensíveis; registrar apenas SQLSTATE.
            Log::error('Database operation failed', ['sqlstate' => (string) $exception->getCode()]);
        })->stop();
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'health/*') || $request->expectsJson(),
        );
    })->create();
