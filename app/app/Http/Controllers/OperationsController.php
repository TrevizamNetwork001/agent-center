<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Web\OperationalSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperationsController extends Controller
{
    public function __invoke(Request $r, OperationalSnapshot $snapshot)
    {
        Gate::authorize('manage-diagnostic-catalog');
        $data = ['health' => $snapshot->get(), 'agents' => Agent::orderBy('name')->paginate(25)];
        if ($r->boolean('fragment')) {
            return response()->view('diagnostics.operations-content', $data)->header('X-AC-Fragment', 'operations-panel');
        }

        return view('diagnostics.operations', $data);
    }
}
