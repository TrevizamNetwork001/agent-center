@php($r=$step->result??[])@php($d=$r['data']??[])@php($state=\App\Web\Presentation::stepStatus($step))
@if($state==='POLICY_BLOCKED')
    <span class="evidence-status">Bloqueado por política · teste não executado</span>
@elseif($step->status==='SKIPPED')
    <span class="evidence-status">Verificação dispensada neste diagnóstico</span>
@elseif($step->capability==='dns_lookup:v1')
    <div class="probe-result"><strong>DNS {{$d['record_type']??($step->definition['input']['record_type']??'')}}</strong><div class="probe-addresses">@forelse($d['answers']??[] as $address)<code>{{$address}}</code>@empty<span>Sem respostas disponíveis</span>@endforelse</div></div>
    @if(isset($d['transport']))<span class="probe-meta">{{strtoupper($d['transport'])}} · resolvedor {{$d['resolver']}}:53 · RCODE {{$d['rcode']}}</span>@if($d['truncated'])<span>Resposta truncada · confira o teste TCP</span>@endif @endif
@elseif($step->capability==='tcp_connect:v1')
    <div class="probe-result"><strong>TCP 443</strong><span>{{isset($d['connected'])?($d['connected']?'Conexão estabelecida':'Conexão não estabelecida'):\App\Web\Presentation::label($state)}}</span></div>
@elseif($step->capability==='http_probe:v1')
    <div class="probe-result"><strong>HTTPS</strong>@if(isset($d['status']))<span>HTTP {{$d['status']}} · TLS {{$d['tls']?'validado':'não validado'}}</span>@else<span>{{\App\Web\Presentation::label($state)}}</span>@endif</div>
    @if(($d['status']??0)>=300 && ($d['status']??0)<400)<span class="probe-note">Redirecionamento recebido. O destino do redirecionamento não foi testado.</span>@endif
@elseif($step->capability==='icmp_ping:v1')
    <div class="probe-result"><strong>ICMP</strong>@if(isset($d['loss_percent']))<span>Perda {{$d['loss_percent']}}% · {{$d['received']}} de {{$d['sent']}} respostas</span>@if(isset($d['avg_ms']))<span>Latência média {{number_format($d['avg_ms'],2,',','.')}} ms</span>@endif @else<span>{{\App\Web\Presentation::label($state)}}</span>@endif</div>
@else
    <div class="probe-result"><strong>Rota</strong><span>{{isset($d['hops'])?count($d['hops']).' saltos observados':\App\Web\Presentation::label($state)}}</span></div>
    @if(isset($d['reached_destination']))<span>{{$d['reached_destination']?'Destino alcançado':'Destino não alcançado no limite de 5 saltos'}}</span>@endif
    @if(!empty($d['hops']))<span class="mono">{{implode(' → ',$d['hops'])}}</span>@endif
@endif
@if(isset($d['duration_ms']))<span class="probe-duration">Tempo da sondagem: {{\App\Diagnostics\Timing::probe((float)$d['duration_ms'])}}</span>@endif
@if(($error=$r['error']??$d['error']??$step->error_code??null) && !in_array($error,['policy_denied','condition_not_met']))<span>{{\App\Web\Presentation::diagnosticError($error)}}</span>@endif
