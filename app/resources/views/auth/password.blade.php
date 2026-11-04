@extends('layout')
@section('content')
<section class="card"><h1>Alterar senha</h1>
@if(auth()->user()->must_change_password)<p class="error">Troque a senha temporária para continuar.</p>@endif
<p>Mínimo de 14 caracteres, incluindo maiúscula, minúscula, número e símbolo.</p>
<form method="post" action="{{ route('password.update') }}">@csrf @method('PUT')
<label>Senha atual<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>Nova senha<input type="password" name="password" autocomplete="new-password" minlength="14" maxlength="128" required></label>
<label>Confirmar nova senha<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button>Salvar nova senha</button></form></section>
@endsection
