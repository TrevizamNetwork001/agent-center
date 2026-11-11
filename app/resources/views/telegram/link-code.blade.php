@extends('layout')
@section('title','Vincular Telegram')
@section('content')
<section class="card"><h1>Vincular Telegram</h1><p>No Telegram, abra uma conversa privada com <strong>{{'@'.$username}}</strong> e envie este comando:</p><p><code>{{'/start '.$code}}</code></p><p>Código válido por 10 minutos e de uso único. Não o compartilhe. Após enviar, volte ao painel e confirme se o ID Telegram apresentado é o seu.</p><a class="button" href="{{route('telegram.index')}}">Conferir e confirmar vínculo</a></section>
@endsection
