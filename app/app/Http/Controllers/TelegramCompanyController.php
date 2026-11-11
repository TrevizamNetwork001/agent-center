<?php

namespace App\Http\Controllers;

use App\Telegram\AdministratorAssociation;
use App\Telegram\Bot;
use App\Telegram\Group;
use App\Telegram\Groups;
use App\Telegram\Link;
use App\Telegram\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TelegramCompanyController extends Controller
{
    public function associate(Request $request, AdministratorAssociation $associations)
    {
        Gate::authorize('manage-intelligence');
        abort_unless(config('telegram.admin_association_enabled'), 403);
        $data = $request->validate(['bot_id' => 'required|uuid', 'generation' => 'required|uuid', 'user_id' => 'required|integer|min:1', 'telegram_user_id' => ['required', 'regex:/^[1-9][0-9]{0,15}$/D'], 'association_ack' => 'accepted', 'tenant_id' => 'prohibited', 'role' => 'prohibited', 'status' => 'prohibited']);
        $bot = Bot::whereKey($data['bot_id'])->firstOrFail();
        abort_unless($bot->generation === $data['generation'], 409);
        $associations->assign($request->user(), $bot, (int) $data['user_id'], (int) $data['telegram_user_id']);

        return redirect()->to(route('telegram.index').'#company-groups')->with('success', 'ID Telegram associado ao colaborador e autorização registrada. As permissões e a troca de senha obrigatória da conta continuam valendo. O colaborador deve abrir o bot no Telegram para iniciar a conversa.');
    }

    public function issue(Request $request, Groups $groups)
    {
        Gate::authorize('manage-intelligence');
        $data = $request->validate(['reply_mode' => 'required|in:private,group', 'shared_ack' => 'accepted_if:reply_mode,group', 'tenant_id' => 'prohibited']);
        $bot = Bot::firstOrFail();
        [$group, $code] = $groups->issue($request->user(), $bot, $data['reply_mode'], $request->boolean('shared_ack'));

        return response()->view('telegram.group-code', ['group' => $group, 'code' => $code, 'username' => $bot->username])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function approve(Request $request, string $group, Groups $groups)
    {
        Gate::authorize('manage-intelligence');
        $groups->approve(Group::findOrFail($group), $request->user());

        return redirect()->route('telegram.index')->with('success', 'Grupo confirmado. Você foi autorizado como responsável. No grupo e no privado, somente colaboradores liberados serão atendidos.');
    }

    public function revoke(Request $request, string $group, Groups $groups)
    {
        Gate::authorize('manage-intelligence');
        $groups->revoke(Group::findOrFail($group), $request->user());

        return redirect()->route('telegram.index')->with('success', 'Grupo revogado. Novos pedidos e respostas pendentes desse grupo foram bloqueados.');
    }

    public function allow(Request $request, Groups $groups)
    {
        Gate::authorize('manage-intelligence');
        $data = $request->validate(['telegram_user_id' => ['required', 'regex:/^[1-9][0-9]{0,15}$/D'], 'tenant_id' => 'prohibited']);
        $bot = Bot::firstOrFail();
        $link = Link::where('bot_id', $bot->id)->where('telegram_user_id', $data['telegram_user_id'])->where('status', 'ACTIVE')->first();
        if (! $link) {
            return back()->withErrors(['telegram_user_id' => 'Esse ID ainda não possui um vínculo verificado neste tenant. O colaborador precisa vincular e confirmar a conta pelo painel.']);
        }
        $groups->authorizeMember($bot, $link, $request->user());

        return redirect()->route('telegram.index')->with('success', 'Colaborador autorizado pelo ID verificado. A liberação vale no grupo da empresa e no privado do bot.');
    }

    public function deny(Request $request, string $member, Groups $groups)
    {
        Gate::authorize('manage-intelligence');
        $groups->revokeMember(Member::findOrFail($member), $request->user());

        return redirect()->route('telegram.index')->with('success', 'Autorização revogada no grupo e no privado. Confirmações pendentes foram invalidadas.');
    }
}
