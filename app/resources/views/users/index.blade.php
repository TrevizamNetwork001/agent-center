@extends('layout')
@section('title','Usuários')
@section('content')
<div class="page-header"><div><div class="eyebrow">GOVERNAR</div><h1>Usuários do tenant selecionado</h1><p>Contas, papéis e acesso no contexto atual.</p></div></div>
<section class="card table-card"><div class="table table-responsive" tabindex="0" role="region" aria-label="Lista de usuários"><table><thead><tr><th scope="col">Nome</th><th scope="col">Email</th><th scope="col">Papel</th><th scope="col">Status</th><th scope="col">Ação</th></tr></thead><tbody>
@forelse($users as $listed)<tr><td data-label="Nome">{{ $listed->name }}</td><td data-label="Email">{{ $listed->email }}</td><td data-label="Papel">{{ \App\Web\Presentation::label($listed->role->value) }}</td><td data-label="Status"><x-badge :value="$listed->status"/></td><td data-label="Ação">@can('update', $listed)<a href="{{ route('users.edit', $listed) }}">Editar<span class="sr-only"> usuário {{ $listed->name }}</span></a>@else<span class="muted">Sem permissão de edição</span>@endcan</td></tr>@empty<tr><td colspan="5"><div class="empty"><h2>Nenhum usuário encontrado</h2><p>As contas deste tenant aparecerão aqui.</p></div></td></tr>@endforelse
</tbody></table></div>@include('shared.pagination',['items'=>$users])</section>
<section class="card"><h2>Criar usuário</h2><form method="post" action="{{ route('users.store') }}">@csrf @include('users.fields')
<p>Defina uma senha temporária forte e entregue-a por canal seguro. A troca será obrigatória no primeiro login.</p>
<label>Senha temporária<input type="password" name="password" autocomplete="new-password" minlength="14" maxlength="128" required></label>
<label>Confirmar senha<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button>Criar usuário</button></form></section>
@endsection
