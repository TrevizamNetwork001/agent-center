<section class="card" id="device-analysis"><h2>Laboratório de análise</h2>
<p><strong>LABORATÓRIO / SIMULADO · SIMULATED / LAB · acesso real desabilitado.</strong> Consultas tipadas para Huawei NE8000/VRP 8 e MikroTik CCR/RouterOS 7. A compatibilidade abaixo considera apenas os dados declarados no cadastro, sem validar um equipamento real.</p>
<p>Demonstração administrativa com fixtures. Não cria tarefas, alertas ou evidências operacionais e não altera o estado do equipamento. Fabricante: {{$catalog['manufacturers'][$nocDevice->manufacturer]}} · plataforma: {{$catalog['platforms'][$nocDevice->platform]['label']}}.</p>
@foreach($analysisContracts as $contract)
<details style="overflow-wrap:anywhere"><summary>{{$contract['objective']}} · {{$contract['implementation_state']}} · {{$contract['compatibility']==='LAB_DECLARED_COMPATIBLE'?'Compatível para laboratório':'Não disponível para esta plataforma/versão'}}</summary>
<p>{{$contract['id']}} · {{$contract['limitation']}}</p>
<p>Limites: {{$contract['max_evidence_bytes']}} bytes de evidência, {{$contract['max_rows']}} registros, {{$contract['timeout_ms']}} ms previstos. Nenhum transporte de equipamento implementado.</p>
<p>Pré-requisitos: {{implode('; ',$contract['prerequisites'])}}.</p>
<details><summary>Contrato, compatibilidade e schemas</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{json_encode($contract, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)}}</pre></details>
@php($scenarios=array_filter($analysisFixtures,fn($f)=>$f['contract_id']===$contract['id']))
@can('demonstrate',$nocDevice)
@if($contract['admin_demo_enabled'] && count($scenarios))
<form method="post" action="{{route('noc.analysis-demo',$nocDevice)}}" style="margin-top:1rem">@csrf
<input type="hidden" name="request_key" value="{{Illuminate\Support\Str::uuid()}}"><input type="hidden" name="device_revision" value="{{$nocDevice->revision}}">
<label>Cenário de {{$contract['objective']}}<select name="fixture" required>@foreach($scenarios as $key=>$f)<option value="{{$key}}">{{$f['label']}} · {{$key}}</option>@endforeach</select></label>
@if($contract['kind']==='route')<label>Destino do cenário<input name="parameters[destination]" value="8.8.8.8" readonly required></label><p class="form-note">Destino fixo da fixture; nenhuma consulta de rede.</p>@endif
@if($contract['kind']==='resources')<label>Referência de CPU do laboratório (%)<input name="parameters[cpu_reference_percent]" type="number" min="1" max="100" step="0.01" placeholder="Usar referência da fixture"></label><label>Referência de memória do laboratório (%)<input name="parameters[memory_reference_percent]" type="number" min="1" max="100" step="0.01" placeholder="Usar referência da fixture"></label>@endif
<button>Gerar demonstração SIMULADA</button></form>
@else<p>Laboratório administrativo não habilitado para este tenant ou cenário não implementado. Nenhum executor real disponível.</p>@endif
@else<p>Seu perfil permite consultar os contratos, mas não gerar simulações.</p>@endcan
</details>
@endforeach
</section>
