@php($pendingUpdate = $operations->first(fn ($operation) => !in_array($operation->status, ['COMPLETED', 'FAILED'], true)))
@php($availableUpdates = \App\Agent\ReleaseAvailability::newer($releases, $agent->version, $agent->metadata['lifecycle_components'] ?? [], (int)($agent->metadata['lifecycle_release_sequence'] ?? 0)))
@php($updaterReady = \App\Agent\Protocol::updaterReady($agent))
<div class="page-header"><div><h1>{{($operations->first()?->reason === 'restart' ? 'Reiniciando ' : 'Atualizando ').$agent->name}}</h1><p>Ao concluir, você voltará automaticamente à página do Agent.</p></div><a href="{{route('agents.show', $agent)}}">Voltar ao Agent</a></div>
@include('agents.update-panel')
