@extends('layout')
@section('title',$agent->name)
@section('content')<div id="agent-detail" data-live-panel data-interval="10000" data-tenant="{{app(\App\Tenancy\TenantContext::class)->tenantId}}">@include('agents.detail')</div>@include('shared.refresh',['panel'=>'agent-detail'])@endsection
