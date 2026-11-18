@can('revoke', $agent)
    <p><a href="{{route('agents.releases.index')}}">Catálogo de versões assinadas</a></p>
    @foreach($agent->metadata['lifecycle_components'] ?? [] as $component)
        <p>Componente {{$component['id']}}: {{$component['version']}} · {{$component['status']}} · {{$component['operational_verified'] ? 'Instalação validada; não substitui a verificação atual de saúde' : 'Validação simulada de laboratório; não homologado'}}.</p>
    @endforeach
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
                        <option value="{{$release['sha256']}}" @disabled((isset($release['lifecycle']) && ($agent->metadata['updater']['signed_release_protocol'] ?? 0) !== 1) || (!empty($release['components']) && ($agent->metadata['updater']['component_protocol'] ?? 0) !== 1))>{{$release['version']}}@foreach($release['components'] ?? [] as $component) · {{$component['id']}} {{$component['version']}}@endforeach</option>
                    @endforeach
                </select></label>
                <details><summary>Revisar compatibilidade das versões</summary>
                    @foreach($availableUpdates as $release)
                        <p>{{$release['version']}} · {{$release['compatibility'] ?? 'Compatibilidade não informada'}}
                        @if(isset($release['lifecycle'])) · Exige atualizador com protocolo de assinatura 1. @endif</p>
                        @foreach($release['components'] ?? [] as $component)
                            <p>Componente {{$component['id']}} · {{$component['version']}} · perfil {{$component['profile']}} · dependências {{implode(', ', $component['dependencies'])}}. Exige aprovação local prévia do perfil.</p>
                        @endforeach
                    @endforeach
                </details>
                <p>A atualização verifica o pacote, faz backup e instala após sua confirmação. Ao concluir, você voltará à página do Agent.</p>
                <button>Atualizar Agent</button>
            </form>
            @endif
        @elseif($availableUpdates !== null)
            <p role="status">Não há atualização disponível.</p>
        @endif
        <details>
            <summary>Retorno controlado de versão</summary>
            <form method="post" action="{{route('agents.operations.request', $agent)}}">
                @csrf
                <input type="hidden" name="kind" value="rollback">
                <input type="hidden" name="reason" value="failed_release">
                <label>Release de rollback<select name="release_sha" required>
                    @foreach($releases as $release)
                        <option value="{{$release['sha256']}}">{{$release['version']}}@foreach($release['components'] ?? [] as $component) · {{$component['id']}} {{$component['version']}}@endforeach</option>
                    @endforeach
                </select></label>
                <button @disabled(!$releases)>Verificar rollback</button>
            </form>
        </details>
    @endif
@endcan
