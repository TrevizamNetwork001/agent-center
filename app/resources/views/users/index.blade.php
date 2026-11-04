@extends('layout')
@section('content')
<h1>Usuários do tenant selecionado</h1><section class="card table"><table><thead><tr><th>Nome</th><th>Email</th><th>Papel</th><th>Status</th><th>Ação</th></tr></thead><tbody>
@foreach($users as $listed)<tr><td>{{ $listed->name }}</td><td>{{ $listed->email }}</td><td>{{ $listed->role->value }}</td><td>{{ $listed->status }}</td><td>@can('update', $listed)<a href="{{ route('users.edit', $listed) }}">Editar</a>@endcan</td></tr>@endforeach
</tbody></table>{{ $users->links() }}</section>
<section class="card"><h2>Criar usuário</h2><form method="post" action="{{ route('users.store') }}">@csrf @include('users.fields')
<p>Defina uma senha temporária forte e entregue-a por canal seguro. A troca será obrigatória no primeiro login.</p>
<label>Senha temporária<input type="password" name="password" autocomplete="new-password" minlength="14" maxlength="128" required></label>
<label>Confirmar senha<input type="password" name="password_confirmation" autocomplete="new-password" required></label><button>Criar usuário</button></form></section>
@endsection
