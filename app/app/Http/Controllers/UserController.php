<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', User::class);

        return view('users.index', ['users' => User::orderBy('name')->paginate(25)]);
    }

    public function store(UserRequest $request, TenantContext $context, Audit $audit)
    {
        try {
            DB::transaction(function () use ($request, $context, $audit) {
                $user = new User;
                $user->forceFill([...$request->safe()->only(['name', 'email', 'password', 'role', 'status']), 'tenant_id' => $context->tenantId, 'must_change_password' => true])->save();
                $audit->record('user.created', $request->user(), $context->tenantId, 'user', $user->id, ['role' => $user->role->value, 'status' => $user->status]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'Email indisponível.']);
            }
            throw $exception;
        }

        return redirect()->route('users.index')->with('success', 'Usuário criado; troca de senha obrigatória.');
    }

    public function edit(User $user)
    {
        Gate::authorize('update', $user);

        return view('users.edit', compact('user'));
    }

    public function update(UserRequest $request, User $user, Audit $audit)
    {
        try {
            DB::transaction(function () use ($request, $user, $audit) {
                $wasActive = $user->status === 'active';
                $user->forceFill($request->safe()->only(['name', 'email', 'role', 'status']));
                $fields = array_keys($user->getDirty());
                $user->save();
                if ($user->status === 'disabled') {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $audit->record('user.updated', $request->user(), $user->tenant_id, 'user', $user->id, ['fields' => $fields]);
                if ($wasActive && $user->status === 'disabled') {
                    $audit->record('user.disabled', $request->user(), $user->tenant_id, 'user', $user->id);
                }
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => 'Email indisponível.']);
            }
            throw $exception;
        }

        return redirect()->route('users.index')->with('success', 'Usuário atualizado.');
    }
}
