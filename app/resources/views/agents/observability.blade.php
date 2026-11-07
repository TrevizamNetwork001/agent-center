@can('observe', $agent)
@php
    $observation = \App\Agent\Observability::view($agent);
    $report = $observation['report'];
    $showValue = static fn ($value) => $value === null ? 'INDISPONÍVEL' : (is_bool($value) ? ($value ? 'Sim' : 'Não') : $value);
@endphp
<section class="card" id="agent-observability">
    <h2>Diagnóstico operacional</h2>
    <p class="muted">Relatório autenticado de leitura · contrato v1 · {{ $observation['fresh'] ? 'recente' : 'INDISPONÍVEL ou antigo (stale)' }}. Uma capability instalada não comprova permissão para um destino.</p>
    <details open><summary>Estado geral</summary>
        <dl class="key-values">
            @foreach(['Agent ID' => $agent->id, 'Hostname' => $agent->hostname ?: null, 'Versão' => $agent->version, 'Sistema operacional' => $agent->metadata['telemetry']['os'] ?? null, 'Último heartbeat' => $agent->last_heartbeat_at, 'Último polling' => $agent->last_poll_at, 'Serviço' => $agent->metadata['telemetry']['service_status'] ?? null, 'Relatório recebido' => $report['received_at'] ?? null, 'Revisão aplicada' => $report['policy_revision'] ?? null, 'Digest da política gerenciada' => $report['policy_digest'] ?? null, 'Última aplicação de política' => $report['policy_applied_at'] ?? null, 'Último erro operacional' => $report['last_error'] ?? null] as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ $showValue($value) }}</dd></div>
            @endforeach
        </dl>
    </details>
    <details open><summary>Capabilities</summary>
        <div class="table-scroll"><table><thead><tr><th>Capability</th><th>Código instalado</th><th>Permitida</th><th>Saudável</th><th>Estado</th><th>Motivo</th></tr></thead><tbody>
        @forelse($report['capabilities'] ?? [] as $capability)
            <tr><td>{{ $capability['name'] }}</td><td>{{ $showValue($capability['installed']) }}</td><td>{{ $showValue($observation['fresh'] && $observation['policyApplied'] ? ($capability['permitted'] ?? null) : null) }}</td><td>{{ $showValue($observation['fresh'] ? ($capability['healthy'] ?? null) : null) }}</td><td>{{ $observation['fresh'] && $observation['policyApplied'] ? $capability['state'] : 'UNKNOWN' }}</td><td>{{ $observation['fresh'] && $observation['policyApplied'] ? ($capability['reason'] ?? 'Disponível; destino exige consulta própria') : 'STATUS_UNKNOWN' }}</td></tr>
        @empty<tr><td colspan="6">INDISPONÍVEIS · este Agent ainda não enviou o contrato v1.</td></tr>@endforelse
        </tbody></table></div>
    </details>
    <details><summary>Políticas</summary>
        <p class="muted">Leitura restrita a administradores do tenant. A autorização exige interseção de todas as camadas. Listas vazias recusam destinos MTR; dados ausentes permanecem desconhecidos.</p>
        @foreach(['base' => 'A. ACL-base local', 'ceiling' => 'B. Teto administrativo', 'managed' => 'C. Política gerenciada aplicada'] as $key => $label)
            <h3>{{ $label }}</h3>
            @if(isset($report[$key]))
                <dl class="key-values"><div><dt>Domínios</dt><dd>{{ implode(', ', $report[$key]['allowed_dns_names'] ?? []) ?: 'Nenhum' }}</dd></div><div><dt>Redes</dt><dd>{{ implode(', ', $report[$key]['allowed_networks'] ?? []) ?: 'Nenhuma' }}</dd></div></dl>
            @else<p>INDISPONÍVEL</p>@endif
        @endforeach
        <h3>D. Permissão efetiva</h3><p>Consultar abaixo por destino. Nenhuma lista da Central substitui a decisão do executor e do helper.</p>
    </details>
    <details><summary>Helpers</summary>
        <h3>MTR</h3><dl class="key-values">
        @foreach(['version' => 'Versão', 'installed' => 'Instalado', 'service_active' => 'Serviço ativo', 'socket_available' => 'Socket disponível', 'authenticated' => 'Comunicação autenticada', 'healthy' => 'Healthcheck'] as $field => $label)
            <div><dt>{{ $label }}</dt><dd>{{ $showValue($report['helper'][$field] ?? null) }}{{ !$observation['fresh'] && isset($report['helper'][$field]) ? ' (histórico)' : '' }}</dd></div>
        @endforeach
            <div><dt>Última verificação</dt><dd>{{ $showValue($report['collected_at'] ?? null) }}</dd></div>
            <div><dt>Erro sanitizado</dt><dd>{{ $showValue($report['last_error'] ?? null) }}</dd></div>
        </dl><p class="muted">O helper usa ativação por socket. Comunicação bem-sucedida não é uma medição do estado systemd.</p>
    </details>
    <details><summary>Componentes</summary><dl class="key-values"><div><dt>Runtime</dt><dd>{{ $agent->version }}</dd></div><div><dt>Helper MTR</dt><dd>{{ $showValue($report['helper']['version'] ?? null) }}</dd></div><div><dt>Updater</dt><dd>{{ $showValue($agent->metadata['updater']['version'] ?? null) }}</dd></div></dl></details>
    <details><summary>Atualizações</summary><p>Última atualização concluída: {{ $showValue($lastUpdate?->finished_at) }}.</p><a href="#agent-update">Abrir Lifecycle Manager</a></details>
    <details><summary>Eventos recentes</summary>@forelse($events as $event)<p>{{ $event->created_at }} · {{ $event->event_type }}</p>@empty<p>INDISPONÍVEIS · sem eventos autorizados nesta consulta.</p>@endforelse</details>
    <h3>Verificar permissão</h3>
    <p class="muted">Consulta assíncrona pelo heartbeat, sem executar sondagens. A resolução DNS necessária à autorização ocorre no Agent. Resultado válido por até 60 segundos e para a revisão consultada; uma execução posterior revalida tudo. DNS consulta autorização A; TCP e HTTP consultam TCP/443 e HTTPS; Agent info não usa o destino. O teto administrativo do helper se aplica ao MTR, conforme o executor existente.</p>
    <form method="post" action="{{ route('agents.permission-check', $agent) }}">
        @csrf
        <label>Skill<select name="capability">@foreach(\App\Agent\Protocol::CAPABILITIES as $capability)<option value="{{ $capability }}" @selected($capability === 'mtr_path_analysis:v1')>{{ $capability }}</option>@endforeach</select></label>
        <label>Destino<input name="destination" maxlength="253" required placeholder="google.com" autocomplete="off"></label>
        <label>Protocolo MTR (demais capabilities usam o protocolo indicado acima)<select name="protocol"><option value="icmp">ICMP</option><option value="tcp">TCP/443</option></select></label>
        <button type="submit" @disabled(!$observation['fresh'])>Verificar permissão</button>
    </form>
    @if(\App\Agent\Observability::pending($agent))<p role="status">Aguardando resposta autenticada do Agent. Atualize o detalhe após o próximo heartbeat.</p>@endif
    @if($observation['result'])
        <dl class="key-values" role="status">@foreach(['destination' => 'Destino consultado', 'capability' => 'Capability', 'protocol' => 'Protocolo', 'state' => 'Resultado', 'reason' => 'Motivo', 'layer' => 'Camada responsável', 'checked_at' => 'Verificação'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ $showValue($observation['result'][$key] ?? null) }}</dd></div>@endforeach</dl>
    @elseif(isset($agent->metadata['permission_request']) && !\App\Agent\Observability::pending($agent))<p role="status">UNKNOWN · STATUS_UNKNOWN · sem resposta no prazo. Nenhuma permissão foi confirmada.</p>@endif
</section>
@endcan
