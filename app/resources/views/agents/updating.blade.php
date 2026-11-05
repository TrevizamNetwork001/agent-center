@extends('layout')
@section('title', 'Atualizando '.$agent->name)
@section('content')
<div id="agent-detail" data-live-panel data-interval="3000" data-completion-url="{{route('agents.show', $agent)}}" data-tenant="{{app(\App\Tenancy\TenantContext::class)->tenantId}}">@include('agents.update-content')</div>
@include('shared.refresh', ['panel' => 'agent-detail'])
@endsection
