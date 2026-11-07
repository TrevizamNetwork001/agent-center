<section class="card" aria-label="Acesso à atualização do Agent">
    <div class="actions">
        @if($pendingUpdate)
            <a class="button" href="#agent-update">Acompanhar atualização</a>
        @elseif($availableUpdates)
            <a class="button" href="#agent-update">Atualizar Agent</a>
        @endif
        <span>Versão instalada: {{$agent->version}}</span>
    </div>
    @if(!$pendingUpdate)
    @if($availableUpdates === null)
        <p>Versão instalada indisponível para comparação.</p>
    @elseif(!$availableUpdates)
        <p role="status">Não há atualização disponível.</p>
    @else
        <p>Nova versão disponível: {{$availableUpdates[0]['version']}}.</p>
    @endif
    @endif
</section>
