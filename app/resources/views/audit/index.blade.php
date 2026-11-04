@extends('layout')
@section('content')
<section class="card"><h1>Auditoria do contexto atual</h1><p>Eventos de segurança e identidade. Registros imutáveis; IPs e credenciais não são armazenados nesta auditoria.</p>@include('audit.table'){{ $events->links() }}</section>
@endsection
