@extends('layout')
@section('title','Conversa')
@section('content')
<div class="page-header"><div><div class="eyebrow">INVESTIGAR</div><h1>Conversa</h1><p>Tom adaptado automaticamente. Um espaço para conversar, consultar referências e acompanhar diagnósticos.</p></div><a class="button button-secondary" href="{{route('conversations.index')}}">Histórico e nova conversa</a></div>
<div id="conversation-panel">@include('conversations.panel')</div>
@if($conversation->channel==='telegram')<section class="card"><p>Histórico do Telegram. Envie mensagens pelo bot vinculado para manter a identidade e o contexto do canal.</p><a href="{{route('telegram.index')}}">Gerenciar vínculo</a></section>@else
@can('send',$conversation)<section class="card"><form method="post" action="{{route('conversations.send',$conversation)}}" data-conversation-form data-sync="{{route('conversations.sync',$conversation)}}" data-tenant="{{$conversation->tenant_id}}">@csrf<input name="request_key" type="hidden" value="{{Illuminate\Support\Str::uuid()}}"><label for="conversation-body">Mensagem</label><textarea id="conversation-body" name="body" maxlength="2000" rows="3" required placeholder="Pode falar do seu jeito…" aria-describedby="conversation-help"></textarea><p id="conversation-help">Não envie senhas, tokens ou credenciais. Conversar não inicia testes. Os diagnósticos aparecem separados no histórico.</p><div class="actions"><button type="submit">Enviar</button></div><p data-conversation-feedback role="status" aria-live="polite"></p></form></section>@endcan
@endif
<script src="{{asset('conversation.js')}}" defer></script>
@endsection
