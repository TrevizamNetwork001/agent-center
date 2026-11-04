<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditFilterRequest;
use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

class AuditController extends Controller
{
    public function __invoke(AuditFilterRequest $r)
    {
        $f = $r->validated();
        $q = AuditEvent::query();
        if ($f['from'] ?? null) {
            $q->where('created_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay());
        }if ($f['until'] ?? null) {
            $q->where('created_at', '<', CarbonImmutable::parse($f['until'])->addDay()->startOfDay());
        }
        if ($f['event'] ?? null) {
            $q->where('event_type', $f['event']);
        }if ($f['user_id'] ?? null) {
            $q->where('user_id', $f['user_id']);
        }if ($f['entity'] ?? null) {
            $q->where('entity_type', $f['entity']);
        }

        return view('audit.index', ['events' => $q->with(['actor', 'tenant'])->latest('id')->paginate(30)->withQueryString(), 'actors' => User::orderBy('name')->limit(100)->get(['id', 'name']), 'eventTypes' => AuditEvent::select('event_type')->distinct()->orderBy('event_type')->limit(80)->pluck('event_type')]);
    }
}
