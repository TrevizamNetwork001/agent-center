<?php

namespace App\Http\Controllers;

use App\Conversation\Engine;
use App\Conversation\WebAdapter;
use App\Http\Requests\ConversationRequest;
use App\Llm\Interpretation;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticInterpretation;
use App\Models\DiagnosticStep;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Engine $engine)
    {
        Gate::authorize('viewAny', Diagnostic::class);

        return view('conversations.index', ['enabled' => $engine->enabled(), 'items' => Conversation::where('user_id', auth()->id())->orderByDesc('last_activity_at')->paginate(25), 'agents' => Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1)]);
    }

    public function store(ConversationRequest $r, Engine $engine)
    {
        $c = $engine->create(app(WebAdapter::class)->identity($r), $r->validated('request_key'), $r->validated('agent_id'));

        return redirect()->route('conversations.show', $c);
    }

    public function show(Conversation $conversation, Engine $engine)
    {
        Gate::authorize('view', $conversation);
        abort_unless($engine->enabled(), 403);

        return view('conversations.show', $this->data($conversation));
    }

    public function send(ConversationRequest $r, Conversation $conversation, Engine $engine)
    {
        $c = $engine->send(app(WebAdapter::class)->identity($r), $conversation, $r->validated('request_key'), $r->validated('body'));

        return $r->expectsJson() ? response()->json(['url' => route('conversations.show', $c)]) : redirect()->route('conversations.show', $c);
    }

    public function sync(Request $r, Conversation $conversation, Engine $engine)
    {
        $c = $engine->sync(app(WebAdapter::class)->identity($r), $conversation);

        return response()->view('conversations.panel', $this->data($c))->header('X-AC-Fragment', 'conversation-panel')->header('Cache-Control', 'no-store');
    }

    public function interpret(Request $r, Conversation $conversation, Engine $engine)
    {
        Gate::authorize('send', $conversation);
        $c = $engine->sync(app(WebAdapter::class)->identity($r), $conversation);
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        Gate::authorize('view', $d);
        abort_unless($d->status === 'COMPLETED', 422);
        if (! DiagnosticInterpretation::where('diagnostic_id', $d->id)->exists()) {
            app(Interpretation::class)->run($d, $r->user());
        }

        return back();
    }

    public function settings(Request $r)
    {
        Gate::authorize('manage-intelligence');
        $v = $r->validate(['enabled' => 'required|boolean', 'retention_days' => 'required|integer|min:7|max:365']);
        $t = app(TenantContext::class)->tenantId;
        DB::transaction(function () use ($v, $t, $r) {
            $setting = ConversationSetting::where('tenant_id', $t)->first() ?? new ConversationSetting;
            $setting->forceFill(['tenant_id' => $t, ...$v, 'updated_by' => $r->user()->id])->save();
            app(Audit::class)->record('conversation.rollout_updated', $r->user(), $t, 'conversation');
        });

        return back()->with('success', 'Configuração aplicada somente ao tenant selecionado.');
    }

    private function data(Conversation $c): array
    {
        return ['conversation' => $c, 'messages' => ConversationMessage::where('conversation_id', $c->id)->orderBy('sequence')->get(), 'diagnostic' => $c->current_diagnostic_id ? Diagnostic::find($c->current_diagnostic_id) : null, 'steps' => $c->current_diagnostic_id ? DiagnosticStep::where('diagnostic_id', $c->current_diagnostic_id)->orderBy('wave')->orderBy('key')->get() : collect()];
    }
}
