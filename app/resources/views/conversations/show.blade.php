@extends('layout')
@section('title','Conversa de diagnóstico')
@section('content')
<div class="page-header"><div><div class="eyebrow">INVESTIGAR</div><h1>Conversa de diagnóstico</h1><p>Tom adaptado automaticamente. A conclusão técnica permanece determinística.</p></div><a class="button button-secondary" href="{{route('conversations.index')}}">Histórico e nova conversa</a></div>
<div id="conversation-panel">@include('conversations.panel')</div>
@can('send',$conversation)<section class="card"><form method="post" action="{{route('conversations.send',$conversation)}}" data-conversation-form data-sync="{{route('conversations.sync',$conversation)}}" data-tenant="{{$conversation->tenant_id}}">@csrf<input name="request_key" type="hidden" value="{{Illuminate\Support\Str::uuid()}}"><label for="conversation-body">Mensagem</label><textarea id="conversation-body" name="body" maxlength="2000" rows="3" required placeholder="Ex.: testa o YouTube" aria-describedby="conversation-help"></textarea><p id="conversation-help">Não envie senhas, tokens ou credenciais. Você pode pedir detalhes, cancelar ou encerrar.</p><div class="actions"><button type="submit">Enviar</button></div><p data-conversation-feedback role="status" aria-live="polite"></p></form></section>@endcan
<script src="{{asset('conversation.js')}}" defer></script>
@endsection
