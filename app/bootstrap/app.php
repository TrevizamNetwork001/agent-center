<?php

use App\Http\Middleware\AgentOriginLimit;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXY_CIDRS', ''))), headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->web(append: [ResolveTenant::class]);
        $middleware->appendToPriorityList(StartSession::class, ResolveTenant::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateAgent::class);
        $middleware->prependToPriorityList(AuthenticateAgent::class, AgentOriginLimit::class);
        $middleware->alias(['password.changed' => RequirePasswordChange::class, 'agent.auth' => AuthenticateAgent::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['body', 'code', 'token', 'lease_token', 'Authorization', 'api_key', 'secret', 'content', 'system_template', 'user_template']);
        $exceptions->report(function (QueryException $exception): void {
            // QueryException pode conter bindings sensíveis; registrar apenas SQLSTATE.
            Log::error('Database operation failed', ['sqlstate' => (string) $exception->getCode()]);
        })->stop();
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'health/*') || $request->expectsJson(),
        );
    })->create();
