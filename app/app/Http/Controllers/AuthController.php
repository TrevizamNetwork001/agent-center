<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordRequest;
use App\Models\Tenant;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function authenticate(LoginRequest $request, TenantContext $context, Audit $audit)
    {
        $key = 'login:'.hash_hmac('sha256', $request->validated('email'), config('app.key'));
        $ipKey = 'login-ip:'.hash_hmac('sha256', (string) $request->ip(), config('app.key'));
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $audit->record('auth.login_failed', metadata: ['reason' => 'throttled']);

            return response()->view('auth.login', ['throttled' => true], 429)->header('Retry-After', '60');
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($ipKey, 60);
        $context->apply(email: $request->validated('email'));
        try {
            $success = Auth::attemptWhen([...$request->safe()->only(['email', 'password']), 'status' => 'active'], function ($user) use ($context) {
                $context->forUser($user);

                return ! $user->tenant_id || Tenant::whereKey($user->tenant_id)->where('status', 'active')->exists();
            });
        } finally {
            $context->apply(userId: Auth::id());
        }
        if ($success) {
            $user = Auth::user();
            $context->forUser($user);
            if ($user->tenant_id && ! Tenant::whereKey($user->tenant_id)->where('status', 'active')->exists()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $context->apply();
                $success = false;
            }
        }
        if (! $success) {
            $audit->record('auth.login_failed', metadata: ['reason' => 'invalid_credentials']);

            return back()->withErrors(['email' => 'Credenciais inválidas ou acesso indisponível.']);
        }
        try {
            DB::transaction(function () use ($user, $audit) {
                $user->forceFill(['last_login_at' => now()])->save();
                $audit->record('auth.login_success', $user, $user->tenant_id);
            });
        } catch (\Throwable $exception) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw $exception;
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('active_tenant_id');

        return redirect()->route($user->must_change_password ? 'password.edit' : 'dashboard');
    }

    public function logout(Request $request, Audit $audit)
    {
        $audit->record('auth.logout', $request->user(), $request->user()->tenant_id);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function password()
    {
        return view('auth.password');
    }

    public function updatePassword(PasswordRequest $request, Audit $audit)
    {
        DB::transaction(function () use ($request, $audit) {
            $user = $request->user();
            $user->forceFill(['password' => $request->validated('password'), 'must_change_password' => false, 'remember_token' => null])->save();
            $audit->record('auth.password_changed', $user, $user->tenant_id);
            DB::table('sessions')->where('user_id', $user->id)->where('id', '<>', $request->session()->getId())->delete();
        });
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Senha alterada.');
    }
}
