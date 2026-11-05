<section class="mtr-path" aria-label="Análise de caminho MTR">
<strong>MTR {{strtoupper($analysis['protocol'])}}{{$analysis['protocol']==='tcp'?' / 443':''}}</strong>
<p>{{$analysis['summary']}}</p>
<p>Destino: <code>{{$d['resolved_ip']}}</code> · {{number_format($d['total_duration_ms']/1000,2,',','.')}} s · {{$d['cycles']}} ciclos</p>
<div class="table-scroll"><table><thead><tr><th>Hop</th><th>IP / hostname</th><th>ASN</th><th>Enviados / recebidos</th><th>Perda</th><th>RTT médio</th><th>Melhor / pior</th><th>StDev RTT</th><th>Interpretação</th></tr></thead><tbody>
@foreach($analysis['hops'] as $hop)<tr><td>{{$hop['hop_index']}}</td><td><code>{{$hop['address']??'???'}}</code>@if($hop['hostname'])<br>{{$hop['hostname']}}@endif</td><td>Não identificado</td><td>{{$hop['sent']}} / {{$hop['received']??'não informado'}}</td><td>{{$hop['loss_percent']}}%</td><td>{{$hop['avg_ms']===null?'—':number_format($hop['avg_ms'],2,',','.').' ms'}}</td><td>{{$hop['best_ms']===null?'—':$hop['best_ms'].' / '.$hop['worst_ms'].' ms'}}</td><td>{{$hop['stdev_ms']===null?'—':$hop['stdev_ms'].' ms'}}</td><td><span class="badge">{{$hop['state']}}</span></td></tr>@endforeach
</tbody></table></div>
<details><summary>Como interpretar este caminho</summary>@foreach($analysis['notes'] as $note)<p>{{$note}}</p>@endforeach</details>
</section>
