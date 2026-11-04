<div class="table"><table><thead><tr><th>Data</th><th>Evento</th><th>Usuário ID</th><th>Entidade</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->created_at->format('d/m/Y H:i:s T') }}</td><td>{{ $event->event_type }}</td><td>{{ $event->user_id ?? '—' }}</td><td>{{ $event->entity_type ?? '—' }} {{ $event->entity_id }}</td></tr>@empty<tr><td colspan="4">Nenhum evento neste contexto.</td></tr>@endforelse
</tbody></table></div>
