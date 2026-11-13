@php($readonlyAdmin = app(\App\Noc\ReadOnlyAdmin::class)->forDevice($nocDevice))
<section class="card" id="device-readonly-admin" aria-labelledby="device-readonly-admin-title">
<h2 id="device-readonly-admin-title">Device Read-Only V1 · estrutura administrativa</h2>
<p><strong>SIMULADO · acesso operacional CONGELADO.</strong> Esta estrutura apresenta contratos e demonstrações com dados fictícios. Não contém executor nem habilita credenciais ou consultas a equipamentos.</p>
<p>Fabricante: {{ $catalog['manufacturers'][$nocDevice->manufacturer] }} · modelo: {{ $nocDevice->model }} · versão declarada: {{ $nocDevice->os_version ?: 'Não informada' }}.</p>
<ul>
@foreach($readonlyAdmin['contracts'] as $contract)
<li><strong>{{ $contract['objective'] }}</strong> · {{ $contract['id'] }} · {{ $contract['compatibility'] === 'LAB_DECLARED_COMPATIBLE' ? 'Compatibilidade declarada para demonstração' : 'Plataforma/versão não disponível para demonstração' }}.</li>
@endforeach
</ul>
<p>Transporte: nenhum. Credenciais de equipamentos: desabilitadas. Dados de demonstração não alteram saúde, alertas ou decisões operacionais.</p>
<a href="#device-analysis">Ver contratos, limites e cenários SIMULADOS</a>
</section>
