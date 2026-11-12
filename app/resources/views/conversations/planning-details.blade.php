@if(isset($m->metadata['diagnostic_planning']))
@php($planning = $m->metadata['diagnostic_planning'])
<details class="form-note" style="overflow-wrap:anywhere" data-detail-key="planning-{{$m->id}}"><summary>Detalhes técnicos deste planejamento</summary>
<p>Registro no momento da resposta: {{$planning['checked_at']}}. Nenhum teste executado para este pedido.</p>
<dl>
<dt>Objetivo</dt><dd>{{$planning['objective']}}</dd>
<dt>Profile</dt><dd>{{$planning['profile']}}</dd>
<dt>Agent</dt><dd>{{$planning['agent_id']}}</dd>
<dt>Destino e protocolo</dt><dd>{{$planning['target']}} · {{$planning['protocol']}}</dd>
<dt>Autorização</dt><dd>{{$planning['authorization']}}</dd>
<dt>Estado da verificação</dt><dd>{{$planning['status']}}</dd>
<dt>Motivo</dt><dd>{{$planning['reason'] ?? 'authorized'}}</dd>
<dt>Consulta</dt><dd>{{$planning['request_id'] ?? 'Não emitida neste planejamento'}}</dd>
<dt>Revisão da política</dt><dd>{{$planning['policy_revision'] ?? 'Não verificada'}}</dd>
<dt>Validade, envio e recebimento (UTC/epoch)</dt><dd>{{$planning['expires_at'] ?? '—'}} · {{$planning['dispatched_at'] ?? '—'}} · {{$planning['received_at'] ?? '—'}}</dd>
<dt>Verificações planejadas</dt><dd>{{implode(', ', $planning['steps'])}}</dd>
</dl></details>
@endif
