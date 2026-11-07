@extends('layout')
@section('title','Agents')
@section('content')
<div class="page-header"><div><div class="eyebrow">REDE DO PROVEDOR</div><h1>Agents</h1><p>Máquinas Linux que executam os testes de rede dentro do provedor.</p></div></div>
@can('create',App\Models\Agent::class)
<details class="card agent-enrollment" id="enrollment">
    <summary><strong>Adicionar Agent no provedor</strong><span class="muted">Instalação no Linux e autorização de acesso</span></summary>
    <div class="enrollment-body">
        <p>Instale o Agent em uma máquina Linux com acesso à rede que deseja testar. Depois da aprovação, gerencie os IPs, as configurações, as atualizações e os diagnósticos por este painel.</p>
        <ol class="enrollment-steps"><li><strong>Instale no Linux</strong><span>Baixe e execute o instalador na máquina do provedor.</span></li><li><strong>Aprove a máquina</strong><span>Confira a identidade e o IP de origem antes de aceitar o cadastro.</span></li><li><strong>Teste a rede</strong><span>Abra o Agent para configurar os destinos e iniciar os diagnósticos.</span></li></ol>
        <a class="button" href="/instalar/">Abrir instalação e passo a passo →</a>
        <p class="muted">O acesso a equipamentos depende de recursos e permissões específicos. Operações de configuração de devices continuam bloqueadas.</p>
        <details><summary>Cadastro avançado com código de uso único</summary><p class="muted">Use quando o instalador solicitar um código. Válido por 15 minutos e exibido apenas uma vez.</p><form method="post" action="{{route('agents.enrollment')}}">@csrf<label>Nome do Agent<input name="name" maxlength="120" required placeholder="Ex.: Provedor · POP Centro"></label><button>Criar código de cadastro</button></form></details>
    </div>
</details>
@endcan
<div id="agents-panel" data-live-panel data-tenant="{{app(\App\Tenancy\TenantContext::class)->tenantId}}" data-interval="10000">@include('agents.list')</div>
@include('shared.refresh',['panel'=>'agents-panel'])
@endsection
