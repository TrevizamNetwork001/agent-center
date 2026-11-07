@extends('layout')
@section('title', 'Acesso de manutenção')
@section('content')
<div class="page-header"><div><h1>Acesso de manutenção · {{ $agent->name }}</h1><p>Acesso autorizado para conferir a VM Debian de laboratório.</p></div><a href="{{ route('agents.show', $agent) }}">Voltar ao Agent</a></div>
<section class="card">
<h2>Configurar SSH</h2>
<p>Informe a senha SSH do usuário da VM. Escolha acesso por uma hora ou até revogação explícita. A senha é armazenada cifrada e não é exibida novamente.</p>
<p>O IP precisa estar previamente autorizado pelo administrador da Central. A identidade do servidor é conferida pela configuração interna da Central.</p>
<form method="post" action="{{ route('agents.maintenance.store', $agent) }}" autocomplete="off">
@csrf @method('PUT')
<input type="hidden" name="revision" value="{{ $access?->revision ?? 0 }}">
<label>IP da VM <input name="host" value="{{ $access?->host }}" required maxlength="45" placeholder="IP da VM teste"></label>
<label>Porta SSH <input name="port" type="number" min="1" max="65535" value="{{ $access?->port ?? 22 }}" required></label>
<label>Usuário <input name="username" value="{{ $access?->username }}" required maxlength="32"></label>
<label>Senha SSH <input name="password" type="password" required maxlength="1024" autocomplete="new-password"></label>
<label>Validade<select name="access_mode"><option value="temporary" @selected(!$access?->until_revoked)>Uma hora</option><option value="until_revoked" @selected($access?->until_revoked)>Até revogação</option></select></label>
<button>Salvar acesso</button>
</form>
</section>
@if($access)
<section class="card"><h2>Verificação</h2>
<p>Validade: {{ $access->until_revoked ? 'Até revogação' : \App\Web\Presentation::time($access->expires_at) }} · {{ $access->isExpired() ? 'Expirado' : 'Válido' }}</p>
@php($labels = ['ready_for_review' => 'Conexão confirmada: VM teste, Debian 13 e systemd. Instalação ainda não executada.', 'expired' => 'Acesso expirado.', 'host_key_mismatch' => 'Identidade do servidor não confere. Nenhuma autenticação enviada.', 'authentication_failed' => 'Autenticação recusada.', 'precheck_failed' => 'Não foi possível concluir o precheck.', 'wrong_environment' => 'Ambiente diferente da VM teste com Debian 13 e systemd.', 'connection_failed' => 'Não foi possível estabelecer a conexão.'])
<p role="status">{{ $labels[$access->check_status] ?? 'Conexão ainda não verificada.' }}</p>
@if($access->checked_at)<p>Verificado em {{ \App\Web\Presentation::time($access->checked_at) }}</p>@endif
<form method="post" action="{{ route('agents.maintenance.check', $agent) }}">@csrf<input type="hidden" name="revision" value="{{ $access->revision }}"><button @disabled($access->isExpired())>Testar conexão e ambiente</button></form>
<form method="post" action="{{ route('agents.maintenance.destroy', $agent) }}">@csrf @method('DELETE')<button class="button-danger">Remover credencial do painel</button></form>
</section>
@endif
<section class="card"><h2>Bootstrap e homologação</h2><p>Backup, instalação e migração permanecem bloqueados até revisão do laboratório isolado. Testar a conexão não instala componentes nem reinicia o Agent.</p><p>O cadastro permite ao painel verificar o acesso. Não concede acesso automático ao assistente desta conversa.</p></section>
@endsection
