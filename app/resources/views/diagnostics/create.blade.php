@extends('layout')
@section('content')<h1>Novo diagnóstico</h1><form class="card" method="post" action="{{route('diagnostics.store')}}">@csrf
<label>Alvo<input name="target" value="{{old('target')}}" required maxlength="512" placeholder="exemplo.com"></label>
<label>Profile<select name="profile_id" required>@foreach($profiles as $p)<option value="{{$p->id}}">{{$p->display_name}}</option>@endforeach</select></label>
<label>Agent<select name="agent_id" required>@foreach($agents as $a)<option value="{{$a->id}}">{{$a->name}}</option>@endforeach</select></label>
<label><input type="checkbox" name="trace_requested" value="1"> Incluir rota explicitamente (se disponível)</label><p class="muted">Somente alvos autorizados na configuração local do Agent serão consultados.</p><button>Executar</button></form>@endsection
