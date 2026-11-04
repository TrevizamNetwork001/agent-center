@extends('layout')
@section('title','Saúde do sistema')
@section('content')<div class="page-header"><div><div class="eyebrow">OPERAÇÕES</div><h1>Saúde do sistema</h1><p>Disponibilidade, presença e sinais que precisam de atenção.</p></div><span class="chip">Contexto do tenant selecionado</span></div><div id="operations-panel" data-live-panel data-tenant="{{app(\App\Tenancy\TenantContext::class)->tenantId}}" data-interval="10000">@include('diagnostics.operations-content')</div>@include('shared.refresh',['panel'=>'operations-panel'])@endsection
