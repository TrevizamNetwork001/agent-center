@extends('layout')
@section('title','Backup externo · R2')
@section('content')
<div class="page-header"><div><div class="eyebrow">ADMINISTRAÇÃO DA PLATAFORMA</div><h1>Backup externo · Cloudflare R2</h1><p>Proteção de toda a plataforma, gerenciada pelo administrador.</p></div><span class="chip">Bucket: agent-center</span></div>
<section class="card"><h2>Situação da proteção</h2><ul class="mini-list"><li><strong>Backup local</strong><span class="chip">{{$health['local']}}</span></li><li><strong>Cópia externa verificada</strong><span class="chip">{{$health['external']}}</span></li><li><strong>Credenciais R2</strong><span>{{$settings['credentials']?'Cadastradas com criptografia':'Aguardando cadastro'}}</span></li><li><strong>Chave pública de recuperação</strong><span>{{$settings['recipient']?'Cadastrada':'Pendente'}}</span></li></ul><p class="form-note">A recuperação completa exige uma restauração em ambiente independente. Retenção e custódia externa devem ser comprovadas separadamente.</p></section>
<section class="card"><h2>Dados do R2</h2><p class="form-note">Use credenciais de leitura e gravação restritas ao bucket agent-center. Os valores cadastrados ficam ocultos. Deixe um campo em branco para manter seu valor atual.</p>
<form method="post" action="{{route('backups.update')}}" autocomplete="off">@csrf @method('PUT')
<label>Account ID<input type="password" name="r2_account_id" autocomplete="off" maxlength="32" spellcheck="false" placeholder="{{$settings['account']?'Já cadastrado · informe apenas para alterar':'Account ID da Cloudflare'}}"></label>
<label>Access Key ID<input type="password" name="r2_access_key_id" autocomplete="off" maxlength="128" spellcheck="false" placeholder="{{$settings['credentials']?'Já cadastrado · informe apenas para alterar':'Access Key ID do R2'}}"></label>
<label>Secret Access Key<input type="password" name="r2_secret_access_key" autocomplete="new-password" maxlength="256" spellcheck="false" placeholder="{{$settings['credentials']?'Já cadastrado · informe apenas para alterar':'Secret Access Key do R2'}}"></label>
<label>Chave pública age<input name="r2_recipient" autocomplete="off" maxlength="62" spellcheck="false" placeholder="age1… · pode ser cadastrada depois"></label>
<p class="form-note">Gere a chave de recuperação no seu computador Windows e guarde a chave privada em um cofre seguro. Cadastre aqui somente a chave pública que começa com age1.</p>
<label><input type="checkbox" name="r2_offsite_ack" value="1"> Confirmo que a chave privada correspondente está guardada fora desta VPS.</label>
<p class="form-note">É possível salvar as credenciais agora e cadastrar a chave pública depois. O envio externo será liberado após completar a configuração.</p>
<button>Salvar configuração R2</button></form></section>
<section class="card"><h2>Verificar a cópia externa</h2><p>O backup inclui banco, configurações, secrets e arquivos persistentes, com criptografia antes do envio e conferência do download.</p>
<form method="post" action="{{route('backups.request')}}">@csrf<button class="button-secondary" @disabled(!$settings['ready'])>Solicitar backup e verificação</button></form>
@if(!$settings['ready'])<p class="form-note">Complete as credenciais e a chave pública para solicitar a verificação.</p>@endif
</section>
@endsection
