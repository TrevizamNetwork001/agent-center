@can('revoke', $agent)
    @if($agent->status === 'active' && !$pendingUpdate)
        @if($availableUpdates)
            @if($updaterReady)
            <form method="post" action="{{route('agents.operations.request', $agent)}}" data-confirm="Atualizar este Agent para a versão selecionada? Após a verificação, haverá uma breve parada para backup e instalação. Se a nova versão falhar, será tentada a recuperação da versão anterior.">
                @csrf
                <input type="hidden" name="kind" value="update">
                <input type="hidden" name="reason" value="maintenance">
                <input type="hidden" name="install_after_check" value="1">
                <label>Nova versão<select name="release_sha" required>
                    @foreach($availableUpdates as $release)
                        <option value="{{$release['sha256']}}">{{$release['version']}}</option>
                    @endforeach
                </select></label>
                <p>A atualização verifica o pacote, faz backup e instala após sua confirmação. Ao concluir, você voltará à página do Agent.</p>
                <button>Atualizar Agent</button>
            </form>
            @endif
        @elseif($availableUpdates !== null)
            <p role="status">Não há atualização disponível.</p>
        @endif
        <details>
            <summary>Rollback controlado</summary>
            <form method="post" action="{{route('agents.operations.request', $agent)}}">
                @csrf
                <input type="hidden" name="kind" value="rollback">
                <input type="hidden" name="reason" value="failed_release">
                <label>Release de rollback<select name="release_sha" required>
                    @foreach($releases as $release)
                        <option value="{{$release['sha256']}}">{{$release['version']}}</option>
                    @endforeach
                </select></label>
                <button @disabled(!$releases)>Verificar rollback</button>
            </form>
        </details>
    @endif
@endcan
