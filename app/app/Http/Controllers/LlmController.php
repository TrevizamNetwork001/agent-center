<?php

namespace App\Http\Controllers;

use App\Http\Requests\LlmRequest;
use App\Llm\Client;
use App\Llm\Configuration;
use App\Llm\Interpretation;
use App\Models\Diagnostic;
use App\Models\LlmBinding;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\Prompt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LlmController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-intelligence');

        return view('intelligence.llm', ['providers' => LlmProvider::orderBy('name')->get(), 'models' => LlmModel::orderBy('name')->get(), 'prompts' => Prompt::where('status', 'PUBLISHED')->orderBy('name')->get(), 'bindings' => LlmBinding::orderBy('purpose')->get(), 'result' => session('llm_test')]);
    }

    public function providerStore(LlmRequest $r, Configuration $c)
    {
        return $this->saveProvider($r, $c, null);
    }

    public function providerUpdate(LlmRequest $r, LlmProvider $provider, Configuration $c)
    {
        return $this->saveProvider($r, $c, $provider);
    }

    private function saveProvider($r, $c, $p)
    {
        try {
            $c->provider($p, $r->validated(), $r->user());
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['base_url' => 'URL não autorizada ou chave mestre indisponível. Verifique a configuração de implantação.']);
        }

        return back()->with('success', 'Provider salvo; secret nunca será devolvido na UI.');
    }

    public function modelStore(LlmRequest $r, Configuration $c)
    {
        $c->model($r->validated(), $r->user());

        return back();
    }

    public function binding(LlmRequest $r, Configuration $c)
    {
        $c->binding($r->validated(), $r->user());

        return back();
    }

    public function defaults(LlmModel $model, Configuration $c)
    {
        Gate::authorize('manage-intelligence');
        $c->defaults($model, request()->user());

        return back()->with('success', 'Binding padrão preparado com envio de contexto sanitizado autorizado.');
    }

    public function test(LlmProvider $provider, Client $client)
    {
        Gate::authorize('manage-intelligence');
        $m = LlmModel::where('provider_id', $provider->id)->where('enabled', true)->first();
        $p = clone $provider;
        $p->timeout = min(3, $provider->timeout);
        $r = $m ? $client->call($p, $m, [['role' => 'system', 'content' => 'Return JSON: {"summary":"ok","explanation":"connection test","next_steps":[]}'], ['role' => 'user', 'content' => 'Connection test only. No diagnostic or tenant data.']]) : ['error' => 'model_missing'];

        return back()->with('llm_test', isset($r['output']) ? 'Conexão disponível.' : 'Teste não concluído: '.($r['error'] ?? 'unavailable'));
    }

    public function interpret(Diagnostic $diagnostic, Interpretation $service)
    {
        Gate::authorize('create', Diagnostic::class);
        Gate::authorize('view', $diagnostic);
        abort_unless(in_array($diagnostic->status, ['COMPLETED', 'FAILED', 'CANCELLED']), 409, 'Aguarde a conclusão técnica.');
        $service->run($diagnostic, request()->user());

        return back();
    }
}
