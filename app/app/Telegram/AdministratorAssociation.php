<?php

namespace App\Telegram;

use App\Enums\Role;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// An explicit administrative identity assignment, recorded separately from self pairing.
class AdministratorAssociation
{
    public function assign(User $actor, Bot $bot, int $userId, int $telegramId): Link
    {
        Gate::forUser($actor)->authorize('manage-intelligence');
        abort_unless($bot->tenant_id === app(TenantContext::class)->tenantId, 403);
        abort_unless(config('telegram.admin_association_enabled'), 403);
        abort_unless(config('telegram.enabled') && $bot->enabled && $telegramId > 0 && $telegramId <= 9999999999999999, 422);

        return DB::transaction(function () use ($actor, $bot, $userId, $telegramId) {
            $current = Bot::whereKey($bot->id)->sharedLock()->firstOrFail();
            abort_unless($current->generation === $bot->generation, 409);
            $bot = $current;
            abort_unless($bot->enabled, 422);
            // Same sender lock as Runner, then same user lock as self pairing.
            DB::select('SELECT pg_advisory_xact_lock(7823, ?::integer)', [(int) sprintf('%u', crc32($bot->id.':'.$telegramId)) % 2147483647]);
            DB::select('SELECT pg_advisory_xact_lock(7821, ?::integer)', [$userId]);
            $user = User::where('tenant_id', $bot->tenant_id)->whereKey($userId)->sharedLock()->firstOrFail();
            if ($user->status !== 'active' || ! in_array($user->role, [Role::TenantAdmin, Role::Operator], true)) {
                throw ValidationException::withMessages(['user_id' => 'Selecione uma conta ativa de Operador ou Administrador deste tenant. Nenhuma permissão da conta foi alterada.']);
            }
            $occupied = Link::where('bot_id', $bot->id)->where('telegram_user_id', $telegramId)->whereIn('status', ['ACTIVE', 'AWAITING_APPROVAL'])->lockForUpdate()->first();
            if ($occupied && $occupied->user_id !== $user->id) {
                throw ValidationException::withMessages(['telegram_user_id' => 'Esse ID já está associado a outro colaborador. Nenhum vínculo foi alterado.']);
            }
            $link = Link::where('bot_id', $bot->id)->where('user_id', $user->id)->where('status', '!=', 'REVOKED')->lockForUpdate()->first();
            if ($link && ($link->status !== 'ACTIVE' || (string) $link->telegram_user_id !== (string) $telegramId)) {
                throw ValidationException::withMessages(['user_id' => 'Esse colaborador já possui um vínculo ou código em andamento. Revogue o vínculo anterior antes de associar outro ID.']);
            }
            if (! $link) {
                $link = new Link;
                $link->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'user_id' => $user->id, 'bot_id' => $bot->id, 'telegram_user_id' => $telegramId, 'chat_id' => $telegramId, 'status' => 'ACTIVE', 'code_hash' => null, 'expires_at' => now(), 'verified_at' => now()])->save();
                app(Audit::class)->record('telegram.link_admin_assigned', $actor, $bot->tenant_id, 'user', $user->id, metadata: ['link_id' => $link->id]);
            }
            DB::select('SELECT pg_advisory_xact_lock(7824, ?::integer)', [(int) sprintf('%u', crc32($bot->id.':'.$link->id)) % 2147483647]);
            $member = Member::where('bot_id', $bot->id)->where('link_id', $link->id)->lockForUpdate()->first() ?? new Member;
            if ($member->status !== 'ACTIVE') {
                $member->forceFill(['id' => $member->id ?: (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'link_id' => $link->id, 'status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now(), 'revoked_at' => null])->save();
                app(Accounts::class)->audit('telegram.member_authorized', $actor);
            }

            return $link;
        });
    }
}
