@if(app(\App\Noc\ReadOnlyLab\Access::class)->enabled())
<section class="card" aria-labelledby="readonly-lab-title">
<h2 id="readonly-lab-title">Consultas SSH · SIMULADO</h2>
<p>Somente servidores fictícios do laboratório isolado. O IP de gerência cadastrado não é utilizado. Nenhuma medição representa equipamento real.</p>
@php($labAccess = \App\Models\DeviceLabAccess::where('device_id', $nocDevice->id)->first())
<p>Estado de acesso ao laboratório: <strong>{{ $labAccess && $labAccess->state === 'approved_lab' && $labAccess->expires_at->isFuture() && $labAccess->device_revision === $nocDevice->revision ? 'Aprovação de laboratório registrada' : 'Não autorizado ou aprovação expirada' }}</strong>.</p>
@can('simulate', $nocDevice)
<div class="actions">
@foreach(\App\Noc\ReadOnlyLab\Operation::cases() as $op)
<form method="post" action="{{ route('noc.readonly-lab', $nocDevice) }}">
@csrf
<input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="device_revision" value="{{ $nocDevice->revision }}">
<input type="hidden" name="operation" value="{{ $op->value }}">
@if($op === \App\Noc\ReadOnlyLab\Operation::Route)<input type="hidden" name="parameters[destination]" value="8.8.8.8">@endif
<button type="submit">SIMULADO · {{ match($op) { \App\Noc\ReadOnlyLab\Operation::Bgp => 'Sessões BGP', \App\Noc\ReadOnlyLab\Operation::Route => 'Rota para 8.8.8.8', \App\Noc\ReadOnlyLab\Operation::Interfaces => 'Interfaces', default => 'CPU e memória' } }}</button>
</form>
@endforeach
</div>
@include('noc.routeros-protocol-lab')
<p class="form-note">Na conversa: “Simule ssh-bgp no NE8000”, “Simule ssh-route no CCR”, “Simule ssh-interfaces no CCR” ou “Simule ssh-resources no NE8000”. Rota de laboratório fixa: 8.8.8.8, tabela main. MTR continua separado, a partir do Agent.</p>
@endcan
</section>
@endif
