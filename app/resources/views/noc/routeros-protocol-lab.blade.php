@if(config('device_readonly_lab.routeros_protocol') === true && app(\App\Noc\Analysis\Contracts::class)->platform($nocDevice) === 'ccr-routeros7')
<section aria-labelledby="routeros-protocol-title">
<h3 id="routeros-protocol-title">RouterOS 7.16.2 · LAB / SIMULADO</h3>
<p>Consultas a um servidor fictício. Cada consulta exige aprovação específica e válida do laboratório. O cadastro não autoriza conexão ao MikroTik.</p>
<div class="actions">
@foreach(\App\Noc\ReadOnlyLab\RouterOs7\Operation::cases() as $routerOperation)
<form method="post" action="{{ route('noc.readonly-lab', $nocDevice) }}">
@csrf
<input type="hidden" name="protocol" value="routeros7-v1">
<input type="hidden" name="operation" value="{{ $routerOperation->value }}">
<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="device_revision" value="{{ $nocDevice->revision }}">
@if($routerOperation === \App\Noc\ReadOnlyLab\RouterOs7\Operation::Route)
<label>Destino IPv4 do cenário<input name="parameters[destination]" value="8.8.8.8" required maxlength="15" autocomplete="off"></label>
<label>Tabela do cenário<input name="parameters[table]" value="main" required maxlength="32" autocomplete="off"></label>
@endif
<button type="submit">SIMULADO · {{ match($routerOperation) { \App\Noc\ReadOnlyLab\RouterOs7\Operation::Identity => 'Identidade', \App\Noc\ReadOnlyLab\RouterOs7\Operation::Bgp => 'Sessões BGP', \App\Noc\ReadOnlyLab\RouterOs7\Operation::Route => 'Consultar rota', \App\Noc\ReadOnlyLab\RouterOs7\Operation::Interfaces => 'Estados das interfaces', \App\Noc\ReadOnlyLab\RouterOs7\Operation::Counters => 'Contadores das interfaces', default => 'Recursos' } }}</button>
</form>
@endforeach
</div>
<p class="form-note">Na conversa: “Simule routeros-bgp no CCR”. Dados técnicos aparecem nas evidências fictícias. Uma consulta de rota não executa MTR, ping ou traceroute.</p>
</section>
@endif
