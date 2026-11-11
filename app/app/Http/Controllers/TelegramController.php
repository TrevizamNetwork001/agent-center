<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use App\Telegram\Accounts;
use App\Telegram\Bot;
use App\Telegram\Client;
use App\Telegram\Deliver;
use App\Telegram\Group;
use App\Telegram\Ingress;
use App\Telegram\Link;
use App\Telegram\Member;
use App\Telegram\Outbound;
use App\Telegram\SetupProgress;
use App\Telegram\Update;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TelegramController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('read-knowledge');
        abort_unless(app(TenantContext::class)->tenantId, 403);
        $admin = Gate::allows('manage-intelligence');
        $bot = Bot::first();
        $links = Link::when(! $admin, fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('created_at')->limit(100)->get();
        $failures = $admin ? Outbound::whereIn('status', ['FAILED', 'UNKNOWN', 'SENDING'])->orderByDesc('updated_at')->limit(30)->get() : collect();
        $events = $admin ? AuditEvent::where('event_type', 'like', 'telegram.%')->latest('created_at')->limit(20)->get() : collect();
        $updates = $admin ? Update::whereIn('status', ['FAILED', 'DENIED'])->latest('created_at')->limit(20)->get() : collect();
        $pending = $admin ? Outbound::where('status', 'PENDING')->count() : 0;

        $setup = app(SetupProgress::class)->forUser($r->user(), $bot);
        $groups = $admin ? Group::latest('created_at')->paginate(10, ['*'], 'groups_page')->withQueryString()->fragment('company-groups') : collect();
        $members = $admin ? Member::latest('approved_at')->paginate(20, ['*'], 'members_page')->withQueryString()->fragment('company-groups') : collect();
        $verifiedLinks = $admin ? Link::where('status', 'ACTIVE')->where('bot_id', $bot?->id)->whereIn('id', $members->pluck('link_id'))->get(['id', 'user_id', 'telegram_user_id']) : collect();

        $companyUsers = $admin ? User::where('tenant_id', app(TenantContext::class)->tenantId)->whereIn('role', [Role::TenantAdmin, Role::Operator])->orderBy('name')->get(['id', 'name', 'role', 'status', 'must_change_password']) : collect();
        $linkIds = $links->pluck('id')->merge($verifiedLinks->pluck('id'))->unique()->all();
        $administratorLinks = AuditEvent::where('event_type', 'telegram.link_admin_assigned')->whereIn('metadata->link_id', $linkIds)->get(['metadata'])->map(fn ($event) => $event->metadata['link_id'])->all();

        return response()->view('telegram.index', compact('admin', 'bot', 'links', 'failures', 'events', 'updates', 'pending', 'setup', 'groups', 'members', 'verifiedLinks', 'companyUsers', 'administratorLinks'))->header('Cache-Control', 'no-store, private');
    }

    public function save(Request $r, Accounts $accounts)
    {
        Gate::authorize('manage-intelligence');
        $data = $r->validate(['bot_token' => 'required|string|max:128', 'tenant_id' => 'prohibited']);
        try {
            $accounts->configure($r->user(), $data['bot_token']);
        } catch (\Throwable) {
            return back()->withErrors(['telegram' => 'Credencial não validada ou bot já cadastrado. Nenhum token é exibido.']);
        }

        return back()->with('success', 'Bot validado e salvo com criptografia. Registre o webhook para ativar. Vínculos anteriores foram revogados.');
    }

    public function webhook(Request $r, int $tenant, string $bot, Ingress $ingress)
    {
        return response()->json($ingress->accept($r, $tenant, $bot));
    }

    public function register(Request $r, Accounts $accounts)
    {
        Gate::authorize('manage-intelligence');
        $r->validate(['enabled' => 'required|boolean', 'tenant_id' => 'prohibited']);
        $accounts->register(Bot::firstOrFail(), $r->user(), $r->boolean('enabled'));

        return back()->with('success', 'Solicitação processada. Confira o estado e a saúde do webhook.');
    }

    public function test(Request $r, Client $client)
    {
        Gate::authorize('manage-intelligence');
        $bot = Bot::firstOrFail();
        $me = $client->call($bot, 'getMe');
        $health = $client->call($bot, 'getWebhookInfo');
        $verified = $me['status'] === 'OK' && (string) ($me['result']['id'] ?? '') === (string) $bot->telegram_bot_id;
        $webhook = $health['status'] === 'OK' && ($health['result']['url'] ?? '') === app(Accounts::class)->webhookUrl($bot);
        $bot->forceFill(['last_checked_at' => now(), 'last_error' => ! $verified ? ($me['status'] === 'OK' ? 'CREDENTIAL_IDENTITY_MISMATCH' : $me['status']) : (! $webhook ? 'WEBHOOK_MISMATCH' : (isset($health['result']['last_error_date']) ? 'TELEGRAM_DELIVERY_ERROR' : null))])->save();
        app(Accounts::class)->audit('telegram.tested', $r->user());

        return back()->with('success', 'Teste administrativo concluído. Confira a saúde da integração.');
    }

    public function issue(Request $r, Accounts $accounts)
    {
        $code = $accounts->issue($r->user(), Bot::firstOrFail());

        // One response only: code never enters session, URL, flash or logs.
        return response()->view('telegram.link-code', ['code' => $code, 'username' => Bot::firstOrFail()->username])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function approve(Request $r, string $link, Accounts $accounts)
    {
        $accounts->approve(Link::findOrFail($link), $r->user());

        return back()->with('success', 'Identidade Telegram confirmada. Vínculo ativo.');
    }

    public function revoke(Request $r, string $link, Accounts $accounts)
    {
        $accounts->revoke(Link::findOrFail($link), $r->user());

        return back()->with('success', 'Vínculo revogado e confirmações pendentes invalidadas.');
    }

    public function retry(Request $r, string $outbound)
    {
        Gate::authorize('manage-intelligence');
        $r->validate(['acknowledge_duplicate' => 'accepted']);
        DB::transaction(function () use ($outbound, $r) {
            $o = Outbound::whereKey($outbound)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($o->status, ['UNKNOWN', 'FAILED'], true) && $o->attempts < 5 && ($o->body_cipher || $o->message_id), 422);
            $o->forceFill(['status' => 'PENDING', 'next_attempt_at' => now(), 'last_error' => null])->save();
            app(Accounts::class)->audit('telegram.delivery_retry', $r->user());
            Deliver::dispatch($o->tenant_id, $o->id)->afterCommit();
        });

        return back()->with('success', 'Reenvio solicitado. O diagnóstico não será executado novamente.');
    }
}
