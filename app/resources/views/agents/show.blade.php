@extends('layout')
@section('content')<h1>{{ $agent->name }}</h1><section class="card"><dl>@foreach(['id','hostname','status','version','enrolled_at','last_heartbeat_at','last_poll_at','revoked_at'] as $field)<dt>{{ $field }}</dt><dd>{{ $agent->$field ?? '—' }}</dd>@endforeach</dl><h2>Capabilities</h2><ul>@foreach($agent->capabilities as $cap)<li>{{ $cap }}</li>@endforeach</ul></section>@can('revoke',$agent)@if(!$agent->revoked_at)<form method="post" action="{{ route('agents.revoke',$agent) }}">@csrf<button>Revogar Agent</button></form>@endif
@endcan
@endsection
