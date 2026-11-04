@extends('layout')
@section('title','Backup externo · R2')
@section('content')
<div class="page-header"><div><div class="eyebrow">ADMINISTRAÇÃO DA PLATAFORMA</div><h1>Backup externo · Cloudflare R2</h1><p>Proteção de toda a plataforma, gerenciada pelo administrador.</p></div><span class="chip">Bucket: agent-center</span></div>
@include('shared.refresh', ['panel' => 'r2-status'])
<div id="r2-status" data-live-panel data-tenant="platform" data-interval="10000" aria-live="polite">
@include('backups.status')
</div>
<section class="card"><h2>Dados do R2</h2><p class="form-note">Use credenciais de leitura e gravação restritas ao bucket agent-center. Os valores cadastrados ficam ocultos. Deixe um campo em branco para manter seu valor atual.</p>
<form method="post" action="{{route('backups.update')}}" autocomplete="off">@csrf @method('PUT')
<label>Account ID · ID da conta<input type="password" name="r2_account_id" autocomplete="off" maxlength="32" pattern="[a-f0-9]{32}" title="Copie o ID da conta Cloudflare, com 32 caracteres, sem URL." spellcheck="false" placeholder="{{$settings['account']?'Já cadastrado · informe apenas para alterar':'Account ID da Cloudflare'}}"></label>
<label>Access Key ID · ID da chave de acesso<input type="password" name="r2_access_key_id" autocomplete="off" maxlength="128" spellcheck="false" placeholder="{{$settings['credentials']?'Já cadastrado · informe apenas para alterar':'Access Key ID do R2'}}"></label>
<label>Secret Access Key · Chave de acesso secreta<input type="password" name="r2_secret_access_key" autocomplete="new-password" maxlength="256" spellcheck="false" placeholder="{{$settings['credentials']?'Já cadastrado · informe apenas para alterar':'Secret Access Key do R2'}}"></label>
<div class="actions"><button type="button" class="button-secondary" data-backup-keygen>Gerar e baixar chave no navegador</button></div><p class="form-note" data-backup-keygen-status role="status">A chave privada é criada neste navegador e baixada neste navegador. Ela não será enviada à aplicação.</p>
<label>Chave pública age<input name="r2_recipient" autocomplete="off" maxlength="62" spellcheck="false" placeholder="{{$settings['recipient']?'Já cadastrada · informe apenas para alterar':'age1… · pode ser cadastrada depois'}}"></label>
<p class="form-note">Use o botão acima para criar a chave no seu computador. Guarde o arquivo baixado em um cofre seguro e em uma segunda cópia fora da VPS. O formulário recebe somente a chave pública que começa com age1.</p>
<label><input type="checkbox" name="r2_offsite_ack" value="1"> Confirmo que a chave privada correspondente está guardada fora desta VPS.</label>
<p class="form-note">É possível salvar as credenciais agora e cadastrar a chave pública depois. O envio externo será liberado após completar a configuração.</p>
<button>Salvar configuração R2</button></form></section>
<section class="card"><h2>Verificar a cópia externa</h2><p>O backup inclui banco, configurações, secrets e arquivos persistentes, com criptografia antes do envio e conferência do download.</p>
<form method="post" action="{{route('backups.request')}}">@csrf<button class="button-secondary" @disabled(!$settings['ready'])>Solicitar backup e verificação</button></form>
@if(!$settings['ready'])<p class="form-note">Complete as credenciais e a chave pública para solicitar a verificação.</p>@endif
</section>
<script src="{{asset('backup-keygen.js')}}?v=1" defer></script>
@endsection
