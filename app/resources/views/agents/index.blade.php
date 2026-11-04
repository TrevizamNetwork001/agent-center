@extends('layout')
@section('content')<h1>Agents</h1><section class="card">@foreach($agents as $agent)<p><a href="{{ route('agents.show',$agent) }}">{{ $agent->name }}</a> · {{ $agent->hostname }} · {{ $agent->status }} · {{ $agent->version }}</p>@endforeach{{ $agents->links() }}</section>
@can('create', App\Models\Agent::class)<section class="card"><h2>Novo enrollment</h2><p>Código de uso único, válido por 15 minutos.</p><form method="post" action="{{ route('agents.enrollment') }}">@csrf<label>Nome<input name="name" maxlength="120" required></label><button>Criar código</button></form></section>@endcan
@endsection
