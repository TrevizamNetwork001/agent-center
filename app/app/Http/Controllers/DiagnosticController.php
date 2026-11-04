<?php

namespace App\Http\Controllers;

use App\Diagnostics\Catalog;
use App\Diagnostics\Engine;
use App\Http\Requests\DiagnosticRequest;
use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use Illuminate\Support\Facades\Gate;

class DiagnosticController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Diagnostic::class);

        return view('diagnostics.index', ['diagnostics' => Diagnostic::latest()->paginate(30)]);
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

    public function show(Diagnostic $diagnostic)
    {
        Gate::authorize('view', $diagnostic);

        return view('diagnostics.show', ['diagnostic' => $diagnostic, 'steps' => DiagnosticStep::where('diagnostic_id', $diagnostic->id)->orderBy('wave')->orderBy('key')->get(), 'plan' => DiagnosticPlan::where('diagnostic_id', $diagnostic->id)->first(), 'conclusion' => DiagnosticConclusion::where('diagnostic_id', $diagnostic->id)->first()]);
    }

    public function cancel(Diagnostic $diagnostic, Engine $engine)
    {
        Gate::authorize('cancel', $diagnostic);
        $engine->cancel($diagnostic, request()->user());

        return back()->with('success', 'Cancelamento solicitado; as evidências serão preservadas.');
    }
}
