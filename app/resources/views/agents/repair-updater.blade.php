@extends('layout')
@section('title', 'Preparar atualização automática')
@section('content')
<div class="page-header"><div><h1>Preparar atualização automática</h1><p>Agent {{$agent->name}}</p></div><a href="{{route('agents.show', $agent)}}#agent-update">Voltar ao Agent</a></div>
<section class="card">
    <h2>Preparação única nesta máquina</h2>
    <p>Abra um terminal com acesso administrativo na máquina <strong>{{$agent->hostname ?: $agent->name}}</strong> e execute:</p>
    <pre>curl --fail --show-error --location --proto '=https' --proto-redir '=https' -o instalar-agent.sh https://agente.trevizamnetwork.com.br/instalar-agent.sh
bash instalar-agent.sh</pre>
    <a class="button button-secondary" href="{{url('/instalar-agent.sh')}}" download>Baixar instalador</a>
    <p>O instalador prepara o atualizador corrigido e o pacote 0.6.1, preservando cadastro e configuração. Não reinstala o Agent nem cria outro cadastro.</p>
</section>
<section class="card">
    <h2>2. Continue pelo painel</h2>
    <p>Quando o terminal informar que o reparo terminou, volte ao Agent, clique em <strong>Atualizar Agent</strong> e confirme. O painel verifica o pacote, acompanha a instalação e volta automaticamente à página do Agent ao concluir.</p>
    <a class="button" href="{{route('agents.show', $agent)}}#agent-update">Acompanhar atualização</a>
    <p>Esta instalação ainda não oferece download automático de pacotes. Após esta preparação, as próximas versões compatíveis serão baixadas e instaladas pelo painel, com sua confirmação.</p>
</section>
@endsection
