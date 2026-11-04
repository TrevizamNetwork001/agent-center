<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Gate;

class AuditController extends Controller
{
    public function __invoke()
    {
        Gate::authorize('view-audit');

        return view('audit.index', ['events' => AuditEvent::latest('id')->paginate(50)]);
    }
}
