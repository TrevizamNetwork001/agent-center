<section id="agent-update" class="card">
    <h2>Atualização do Agent</h2>
    <p><strong>Versão {{$agent->version}}</strong>@if($pendingUpdate) → {{$pendingUpdate->release['version'] ?? 'Indisponível'}}@endif</p>
    @include('agents.update-progress')
    @include('agents.update-status')
    @include('agents.update-form')

    @can('revoke', $agent)
        @foreach($operations->where('status', 'PLANNED') as $operation)
            <form method="post" action="{{route('agents.operations.approve', [$agent, $operation])}}">
                @csrf
                <p>{{$operation->from_version}} → {{$operation->release['version']}} · {{$operation->release['compatibility']}}</p>
                <p class="mono">SHA-256: {{$operation->release['sha256']}}</p>
                <label><input type="checkbox" name="plan_reviewed" value="1" required> Revisei versão, SHA, compatibilidade, backup e parada do serviço.</label>
                <button>Confirmar operação</button>
            </form>
        @endforeach
    @endcan

</section>
