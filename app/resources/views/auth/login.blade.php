@extends('layout')
@section('content')
<section class="card"><h1>Entrar</h1><p class="muted">Use sua conta do Agent Center.</p>
@if($throttled ?? false)<p class="error">Muitas tentativas. Aguarde um minuto.</p>@endif
<form method="post" action="{{ route('login.store') }}">@csrf
<label>Email<input name="email" type="email" autocomplete="username" maxlength="254" required></label>
<label>Senha<input name="password" type="password" autocomplete="current-password" maxlength="1024" required></label><button>Entrar</button></form></section>
@endsection
