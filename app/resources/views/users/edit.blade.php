@extends('layout')
@section('title','Editar usuário')
@section('content')
<section class="card"><h1>Editar usuário</h1><form method="post" action="{{ route('users.update', $user) }}">@csrf @method('PUT') @include('users.fields')<button>Salvar usuário</button></form></section>
@endsection
