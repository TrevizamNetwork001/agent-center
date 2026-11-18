@extends('layout')
@section('title','Editar tenant')
@section('content')
<section class="card"><h1>Editar tenant</h1><p>Desabilitar o tenant encerra as sessões dos seus usuários e impede novos logins.</p>
<form method="post" action="{{ route('tenants.update', $tenant) }}">@csrf @method('PUT') @include('tenants.fields')<button>Salvar tenant</button></form></section>
@endsection
