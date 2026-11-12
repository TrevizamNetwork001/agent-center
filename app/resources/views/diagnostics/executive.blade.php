<section class="card diagnostic-experience" data-diagnostic-experience="v2" data-tone="{{\App\Web\Presentation::tone($experience['classification']??$diagnostic->status)}}" aria-labelledby="diagnostic-executive-title">
    <div class="dx-heading"><div><p class="dx-eyebrow">RESUMO EXECUTIVO</p><h2 id="diagnostic-executive-title">{{$conclusion?'Uma leitura clara do resultado':'Sua investigação está sendo acompanhada'}}</h2></div><x-badge :value="$experience['classification']??$phaseLabel"/></div>
    <p class="dx-conclusion" role="status">{{$experience['summary']??$phase['text']}}</p>
    <dl class="dx-context"><div><dt>Destino investigado</dt><dd>{{$experience['target']}}</dd></div><div><dt>Agent executor</dt><dd>{{$diagnostic->agent?->name??'Indisponível'}}</dd></div><div><dt>Situação registrada</dt><dd>{{$phaseLabel}}</dd></div><div><dt>Perfil autorizado</dt><dd>{{$diagnostic->profile?->display_name??'Indisponível'}}</dd></div></dl>
    @if($conclusion)
        <div class="dx-columns"><div><h3>O que as evidências mostram</h3><ul class="dx-facts">@forelse($experience['facts'] as $fact)<li><strong>{{$fact['label']}}</strong><span>{{\Illuminate\Support\Str::after($fact['text'],$fact['label'].': ')}}</span>@if(!$fact['metrics'])<small>Métricas indisponíveis</small>@endif</li>@empty<li><strong>Sem medições disponíveis</strong><span>Não há evidências de verificações executadas para resumir.</span></li>@endforelse</ul></div>
        <aside class="dx-scope"><h3>Como interpretar</h3>@foreach($experience['limitations'] as $limit)<p>{{$limit}}</p>@endforeach
        @if($experience['not_executed'])<h3>Etapas não executadas ou não concluídas</h3><ul>@foreach($experience['not_executed'] as $item)<li>{{$item}}</li>@endforeach</ul>@endif
        @if($experience['next_steps'])<h3>Próximo passo</h3><p>{{$experience['next_steps'][0]}}</p>@endif<p class="dx-authorization">Um novo teste depende de autorização. Nenhuma execução adicional é iniciada por esta página.</p></aside></div>
    @else
        <p class="dx-live-note">Este estado vem dos registros da operação. O resultado aparecerá automaticamente; você pode continuar usando o painel.</p>
    @endif
    <div class="dx-timeline"><h3>Linha do tempo</h3><ol>
        <li><span>Solicitação aceita</span><time>{{\App\Web\Presentation::time($diagnostic->created_at,'d/m H:i:s')??'Não registrado'}}</time></li>
        <li><span>Plano preparado</span><time>{{\App\Web\Presentation::time($diagnostic->started_at,'d/m H:i:s')??'Aguardando'}}</time></li>
        <li><span>Primeira execução registrada</span><time>{{\App\Web\Presentation::time($steps->whereNotNull('started_at')->min('started_at'),'d/m H:i:s')??'Não registrada'}}</time></li>
        <li><span>Encerramento</span><time>{{\App\Web\Presentation::time($diagnostic->finished_at,'d/m H:i:s')??'Em acompanhamento'}}</time></li>
    </ol></div>
</section>
