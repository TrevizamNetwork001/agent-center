@if(isset($m->metadata['rag_status']))
<small>Consulta à Knowledge · @switch($m->metadata['rag_status']) @case('answered') Resposta assistida @break @case('no_match') Sem referências @break @case('insufficient_evidence') Evidência insuficiente @break @case('index_incomplete') Indexação pendente @break @default LLM indisponível ou resposta rejeitada @endswitch</small>
@if($m->metadata['rag_sources']??[])
<details open><summary>Fontes da resposta</summary><ul>
@foreach($m->metadata['rag_sources'] as $sid)
@php($s=app(\App\Rag\Retrieval::class)->source(auth()->user(),$sid))
<li>@if($s)<a href="{{route('rag.source',$sid)}}">{{$s['title']}}</a> · Versão {{$s['version_number']}} · {{$s['section']}} · Fragmento {{$s['position']+1}} @else Fonte indisponível: publicação revogada ou índice substituído. @endif</li>
@endforeach
</ul></details>
@else<p><small>Sem fontes verificadas para esta resposta.</small></p>@endif
@endif
