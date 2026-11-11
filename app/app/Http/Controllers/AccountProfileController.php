<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AccountProfileController extends Controller
{
    public function edit(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);

        return response()->view('account.profile', ['account' => $request->user()])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user->status === 'active', 403);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'avatar' => ['nullable', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:1024', 'dimensions:max_width=2048,max_height=2048'],
            'remove_avatar' => 'sometimes|boolean',
            'email' => 'prohibited', 'role' => 'prohibited', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'status' => 'prohibited', 'must_change_password' => 'prohibited',
        ]);
        $newPath = null;
        $oldPath = null;
        $remove = $request->boolean('remove_avatar');
        if (! $remove && $request->hasFile('avatar')) {
            $newPath = $request->file('avatar')->store('accounts/'.$user->id.'/avatars', 'local');
            abort_unless(is_string($newPath) && $newPath !== '', 503);
        }
        try {
            DB::transaction(function () use ($user, $data, $newPath, $remove, &$oldPath, $request) {
                // Identity resource: immutable owner ID comes only from the native authenticated session.
                $row = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
                abort_unless($row && $row->status === 'active', 403);
                $changes = ['name' => $data['name'], 'updated_at' => now()];
                if ($newPath || $remove) {
                    $oldPath = $row->avatar_path;
                    $changes['avatar_path'] = $newPath;
                    $changes['avatar_mime'] = $newPath ? $request->file('avatar')->getMimeType() : null;
                }
                DB::table('users')->where('id', $user->id)->update($changes);
                app(Audit::class)->record('user.updated', $user, $user->tenant_id, 'user', $user->id, ['fields' => array_values(array_intersect(array_keys($changes), ['name', 'avatar_path']))]);
            });
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($oldPath && $this->ownedPath($user->id, $oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect()->route('account.edit')->with('success', 'Seu perfil foi atualizado.');
    }

    public function avatar(Request $request)
    {
        $user = $request->user();
        abort_unless($user->status === 'active' && $user->avatar_path && $this->ownedPath($user->id, $user->avatar_path) && in_array($user->avatar_mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($user->avatar_path), 404);

        return response()->file($disk->path($user->avatar_path), ['Content-Type' => $user->avatar_mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    private function ownedPath(int $user, string $path): bool
    {
        return (bool) preg_match('~^accounts/'.$user.'/avatars/[A-Za-z0-9]+\\.(?:jpe?g|png|webp)$~D', $path);
    }
}
