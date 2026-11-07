@extends('layout')
@section('title','Solicitações de Agents')
@section('content')
<div class="page-header"><div><div class="eyebrow">ADMINISTRAÇÃO DA PLATAFORMA</div><h1>Agents aguardando aceite</h1><p>Confira a máquina, escolha o cliente e aceite. O instalador conclui a conexão automaticamente.</p></div><a href="/instalar/" class="button button-secondary">Instalar Agent</a></div>
<p class="muted">Hostname, IP e fingerprint são declarados pela máquina. Confirme a identidade com o cliente antes de aceitar. Solicitações expiram em 30 minutos.</p>
<div id="agent-requests" data-live-panel data-tenant="{{app(\App\Tenancy\TenantContext::class)->tenantId ?? 'platform'}}" data-interval="10000">@include('agents.requests-list')</div>
@include('shared.refresh',['panel'=>'agent-requests'])
@endsection
