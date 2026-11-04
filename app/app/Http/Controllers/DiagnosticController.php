<?php

namespace App\Http\Controllers;

use App\Diagnostics\Catalog;
use App\Diagnostics\Engine;
use App\Http\Requests\DiagnosticRequest;
use App\Http\Requests\HistoryRequest;
use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DiagnosticController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Diagnostic::class);

        return view('diagnostics.index', ['diagnostics' => Diagnostic::with(['profile', 'agent', 'conclusion'])->latest()->paginate(25)]);
    }

    public function history(HistoryRequest $r)
    {
        $f = $r->validated();
        $q = Diagnostic::query();
        if ($f['from'] ?? null) {
            $q->where('created_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay());
        }if ($f['until'] ?? null) {
            $q->where('created_at', '<', CarbonImmutable::parse($f['until'])->addDay()->startOfDay());
        }
        foreach (['profile_id', 'agent_id', 'status'] as $key) {
            if ($f[$key] ?? null) {
                $q->where($key, $f[$key]);
            }
        }if ($f['target'] ?? null) {
            $q->where('target', 'ilike', '%'.addcslashes($f['target'], '%_\\').'%');
        }
        if ($f['classification'] ?? null) {
            $q->whereHas('conclusion', fn ($c) => $c->where('classification', $f['classification']));
        }

        return view('diagnostics.history', ['diagnostics' => $q->with(['profile', 'agent', 'conclusion'])->latest()->paginate(25)->withQueryString(), 'profiles' => Profile::orderBy('display_name')->get(['id', 'display_name']), 'agents' => Agent::orderBy('name')->limit(100)->get(['id', 'name'])]);
    }

    public function create()
    {
        Gate::authorize('create', Diagnostic::class);
        app(Catalog::class)->ensure();

        return view('diagnostics.create', ['profiles' => Profile::where('enabled', true)->orderBy('display_name')->get(), 'agents' => Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1)]);
    }

    public function store(DiagnosticRequest $r, Engine $engine)
    {
        $d = $engine->create(Profile::findOrFail($r->validated('profile_id')), Agent::findOrFail($r->validated('agent_id')), $r->validated('target'), $r->user(), ['trace_requested' => $r->boolean('trace_requested')]);

        return redirect()->route('diagnostics.show', $d);
    }

    public function show(Diagnostic $diagnostic, Request $r)
    {
        Gate::authorize('view', $diagnostic);

        $data = ['diagnostic' => $diagnostic, 'steps' => DiagnosticStep::where('diagnostic_id', $diagnostic->id)->orderBy('wave')->orderBy('key')->get(), 'plan' => DiagnosticPlan::where('diagnostic_id', $diagnostic->id)->first(), 'conclusion' => DiagnosticConclusion::where('diagnostic_id', $diagnostic->id)->first()];
        if ($r->boolean('fragment')) {
            return response()->view('diagnostics.result', $data)->header('X-AC-Fragment', 'diagnostic-panel');
        }

        return view('diagnostics.show', $data);
    }

    public function cancel(Diagnostic $diagnostic, Engine $engine)
    {
        Gate::authorize('cancel', $diagnostic);
        $engine->cancel($diagnostic, request()->user());

        return back()->with('success', 'Cancelamento solicitado; as evidências serão preservadas.');
    }
}
