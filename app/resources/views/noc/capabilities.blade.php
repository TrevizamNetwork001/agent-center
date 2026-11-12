@extends('layout')
@section('title','Capacidades NOC')
@section('content')
<div class="page-header"><div><div class="eyebrow">INVENTÁRIO NOC · CATÁLOGO V{{$catalog['version']}}</div><h1>Capacidades documentadas</h1><p>Objetivos, evidências previstas e limites por plataforma.</p></div><a href="{{route('noc.index')}}">Voltar ao inventário</a></div>
<section class="card"><h2>Estado de implementação</h2><p>DOCUMENTED: contrato descritivo. SIMULATED: demonstração com fixture. AVAILABLE: exige executor homologado, inexistente neste módulo. UNAVAILABLE: implementação indisponível. Nenhuma capacidade deste catálogo está AVAILABLE.</p><p>As plataformas listadas são candidatas documentais. Modelo, hardware, firmware e versão precisam de homologação individual.</p></section>
@foreach(collect($catalog['capabilities'])->groupBy('category') as $category=>$caps)<section class="card"><h2>{{['routing'=>'Routing','system'=>'Sistema','switching'=>'Switching','ftth'=>'FTTH','bng'=>'BNG / PPPoE'][$category]}}</h2>@foreach($caps as $cap)@include('noc.capability')@endforeach</section>@endforeach
<section class="card"><h2>Plataformas previstas</h2>@foreach($catalog['platforms'] as $platform)<details><summary>{{$platform['label']}}</summary><p>Famílias de referência: {{$platform['model_examples']}}. {{$platform['limitations']}}</p></details>@endforeach<p>As referências externas foram revisadas como material técnico. Não há comandos, executores importados ou conteúdo publicado automaticamente na Knowledge.</p></section>
@endsection
