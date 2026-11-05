@cannot('revoke', $agent)
    <p role="status">Sua conta pode consultar este Agent. A atualização exige um administrador do tenant.</p>
@endcannot
@if(($pendingUpdate || $availableUpdates) && !$updaterReady)
    @if(\App\Agent\Protocol::supportsDownloads($agent))
        <p role="status">O atualizador está sem contato recente. Aguarde a conexão com a Central para continuar.</p>
    @else
        <p>Uma preparação inicial habilita o download automático das próximas versões. O cadastro e a configuração serão preservados.</p>
        @can('revoke', $agent)
            <a class="button button-secondary" href="{{route('agents.repair-updater', $agent)}}">Preparar atualização automática</a>
        @endcan
    @endif
@endif
