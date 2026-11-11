@extends('layout')
@section('title','Fonte da Knowledge')
@section('content')
<section class="card"><h1>{{$source['title']}}</h1><p>Versão {{$source['version_number']}} · {{$source['section']}} · Fragmento {{$source['position']+1}} · Caracteres {{$source['offset_start']}}–{{$source['offset_end']}}</p><p style="white-space:pre-wrap;overflow-wrap:anywhere">{{$source['content']}}</p><a href="{{route('knowledge.show',$source['document_id'])}}">Ver documento de origem</a></section>
@endsection
