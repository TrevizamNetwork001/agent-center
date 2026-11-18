<?php

namespace App\Web;

use App\Models\Diagnostic;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class DashboardData
{
    public function make(array $data, User $user, ?int $tenantId): array
    {
        $tenant = $data['tenant'];
        $healthKeys = ['database', 'worker', 'agents_total', 'agents_online', 'heartbeat_age', 'poll_age', 'missing_heartbeat', 'missing_poll', 'today', 'indications', 'active', 'stale', 'failed', 'state'];

        return [
            'schema_version' => 1,
            'tenant_id' => $tenantId,
            'tenant' => $tenant ? ['name' => $tenant->name] : null,
            'user' => ['name' => $user->name, 'role' => $user->role->value, 'role_label' => Presentation::label($user->role->value)],
            'active_users' => $tenant ? $data['activeUsers'] : null,
            'health' => $tenant ? array_intersect_key($data['health'], array_flip($healthKeys)) : null,
            'recent' => $tenant ? $data['recent']->map(function ($d) {
                $classification = Presentation::conclusion($d)['classification'] ?? null;

                return ['id' => $d->id, 'url' => route('diagnostics.show', $d), 'profile' => $d->profile?->display_name ?? 'Perfil indisponível', 'target' => $d->target, 'agent' => $d->agent?->name ?? 'Agent indisponível', 'status' => $d->status, 'status_label' => Presentation::label($d->status), 'status_tone' => Presentation::tone($d->status), 'classification' => $classification, 'classification_label' => Presentation::label($classification), 'classification_tone' => Presentation::tone($classification), 'duration' => Presentation::duration($d), 'created_at' => $d->created_at->toIso8601String(), 'created_label' => Presentation::time($d->created_at, 'd/m H:i')];
            })->values()->all() : [],
            'events' => $tenant ? $data['events']->map(fn ($event) => ['id' => $event->id, 'type' => $event->event_type, 'actor' => $event->actor?->name ?? ($event->user_id ? 'Conta #'.$event->user_id : 'Sistema'), 'time' => Presentation::time($event->created_at, 'd/m H:i')])->values()->all() : [],
            'links' => ['refresh' => route('dashboard', ['format' => 'json']), 'history' => route('diagnostics.history'), 'create' => Gate::forUser($user)->allows('create', Diagnostic::class) ? route('diagnostics.create') : null, 'audit' => Gate::forUser($user)->allows('view-audit') ? route('audit.index') : null, 'tenants' => route('tenants.index'), 'login' => route('login')],
            'updated_label' => Presentation::time(now(), 'H:i:s'),
        ];
    }
}
