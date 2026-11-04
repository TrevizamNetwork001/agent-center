@extends('layout')
@section('content')
<h1>Dashboard</h1><section class="card"><h2>{{ auth()->user()->name }}</h2>
<p>{{ auth()->user()->email }}</p><p>Papel: <strong>{{ auth()->user()->role->value }}</strong></p>
<p>Tenant: <strong>{{ $tenant?->name ?? 'Contexto da plataforma' }}</strong></p>
@if($activeUsers !== null)<p>Usuários ativos neste tenant: <strong>{{ $activeUsers }}</strong></p>@else<p>Selecione um tenant na página Tenants para administrar seus usuários.</p>@endif</section>
<section class="card"><h2>Eventos recentes</h2>@include('audit.table')</section>
@endsection
