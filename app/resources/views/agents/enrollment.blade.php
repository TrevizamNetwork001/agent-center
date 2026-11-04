@extends('layout')
@section('content')<h1>Enrollment criado</h1><p>Copie para o prompt oculto do Agent. Este código não será exibido novamente. Expira em até 30 minutos, conforme prazo escolhido.</p><pre>{{ $code }}</pre><a href="{{ route('agents.index') }}">Voltar</a>@endsection
