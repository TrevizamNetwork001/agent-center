@extends('layout')
@section('title','Visão geral')
@push('head')
@if(isset($dashboard))
@viteReactRefresh
@vite('resources/js/dashboard.jsx')
@endif
@endpush
@section('content')
<div class="page-header"><div><div class="eyebrow">NOC INTELLIGENCE</div><h1>Visão geral</h1><p>O essencial para observar, investigar e decidir.</p></div>@can('create',App\Models\Diagnostic::class)<a class="button" href="{{route('diagnostics.create')}}"><x-icon name="plus"/>Novo diagnóstico</a>@endcan</div>
@if(isset($dashboard))
<div id="dashboard-react" data-dashboard="{{json_encode($dashboard, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)}}">
@include('dashboard.content')
</div>
@else
@include('dashboard.content')
@endif
@endsection
