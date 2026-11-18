@extends('layout')
@section('title','Tenants')
@section('content')
<div class="page-header"><div><div class="eyebrow">GOVERNAR</div><h1>Tenants</h1><p>Gerencie os contextos isolados da plataforma.</p></div><form method="post" action="{{ route('tenant.select') }}">@csrf<button class="button-secondary">Voltar ao contexto da plataforma</button></form></div>
<section class="card table-card"><div class="table table-responsive" tabindex="0" role="region" aria-label="Lista de tenants"><table><thead><tr><th scope="col">Nome</th><th scope="col">Slug</th><th scope="col">Status</th><th scope="col">Ações</th></tr></thead><tbody>
@forelse($tenants as $listed)<tr><td data-label="Nome">{{ $listed->name }}</td><td data-label="Slug">{{ $listed->slug }}</td><td data-label="Status"><x-badge :value="$listed->status"/></td><td data-label="Ações"><div class="actions"><a href="{{ route('tenants.edit', $listed) }}">Editar<span class="sr-only"> tenant {{ $listed->name }}</span></a>
@if($listed->status === 'active')<form class="inline" method="post" action="{{ route('tenant.select') }}">@csrf<input type="hidden" name="tenant_id" value="{{ $listed->id }}"><button>Selecionar tenant<span class="sr-only"> {{ $listed->name }}</span></button></form>@endif</div></td></tr>@empty<tr><td colspan="4"><div class="empty"><h2>Nenhum tenant encontrado</h2><p>Os contextos cadastrados aparecerão aqui.</p></div></td></tr>@endforelse
</tbody></table></div>@include('shared.pagination',['items'=>$tenants])</section>
<section class="card"><h2>Criar tenant</h2><form method="post" action="{{ route('tenants.store') }}">@csrf @include('tenants.fields')<button>Criar tenant</button></form></section>
@endsection
