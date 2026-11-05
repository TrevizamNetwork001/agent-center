@extends('layout')
@section('content')
<h1>Tenants</h1><section class="card table"><table><thead><tr><th>Nome</th><th>Slug</th><th>Status</th><th>Ações</th></tr></thead><tbody>
@foreach($tenants as $listed)<tr><td>{{ $listed->name }}</td><td>{{ $listed->slug }}</td><td>{{ $listed->status }}</td><td><a href="{{ route('tenants.edit', $listed) }}">Editar</a>
@if($listed->status === 'active')<form class="inline" method="post" action="{{ route('tenant.select') }}">@csrf<input type="hidden" name="tenant_id" value="{{ $listed->id }}"><button>Selecionar tenant</button></form>@endif</td></tr>@endforeach
</tbody></table>{{ $tenants->links() }}
<form method="post" action="{{ route('tenant.select') }}">@csrf<button>Voltar ao contexto da plataforma</button></form></section>
<section class="card"><h2>Criar tenant</h2><form method="post" action="{{ route('tenants.store') }}">@csrf @include('tenants.fields')<button>Criar tenant</button></form></section>
@endsection
