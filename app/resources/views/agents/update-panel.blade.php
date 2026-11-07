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
        @foreach($operations->where('status', 'UNCERTAIN') as $operation)
            @if(isset($operation->release['lifecycle']))
                <form method="post" action="{{route('agents.operations.reconcile', [$agent, $operation])}}">
                    @csrf
                    <p>A recuperação precisa ser verificada pelo Agent antes de liberar novas atualizações. A falha original será preservada.</p>
                    <label><input type="checkbox" name="recovery_reviewed" value="1" required> Revisei a recuperação para a versão anterior e solicito a verificação.</label>
                    <button>Verificar recuperação</button>
                </form>
            @endif
        @endforeach
    @endcan

</section>
