@extends('layout')
@section('content')<h1>Diagnósticos</h1>@can('create',App\Models\Diagnostic::class)<a href="{{route('diagnostics.create')}}">Novo diagnóstico</a>@endcan
<div class="table"><table><tr><th>Alvo</th><th>Status</th><th>Criado</th></tr>@foreach($diagnostics as $d)<tr><td><a href="{{route('diagnostics.show',$d)}}">{{$d->target}}</a></td><td>{{$d->status}}</td><td>{{$d->created_at}}</td></tr>@endforeach</table></div>{{$diagnostics->links()}}@endsection
