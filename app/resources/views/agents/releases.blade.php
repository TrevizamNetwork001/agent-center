@extends('layout')
@section('title','Releases assinadas')
@section('content')
<div class="page-header"><div><div class="eyebrow">LIFECYCLE MANAGER</div><h1>Releases assinadas</h1><p>Artefatos e assinaturas das versões do Agent neste tenant.</p></div><a href="{{ route('agents.index') }}" class="button button-secondary">Voltar aos Agents</a></div>
<section class="card form-card"><h2>Publicar release</h2>
<form method="post" enctype="multipart/form-data" action="{{ route('agents.releases.store') }}">@csrf
<label>Manifesto assinado<textarea name="manifest" rows="8" required maxlength="65536" spellcheck="false"></textarea></label>
<label>Assinatura Ed25519 em base64<input name="signature" required maxlength="128" spellcheck="false" autocomplete="off"></label>
<label>Artefato<input type="file" name="artifact" required></label><button>Publicar release</button></form></section>
<section class="card table-card"><div class="section-head"><h2>Versões publicadas</h2></div><div class="table table-responsive"><table><thead><tr><th scope="col">Versão</th><th scope="col">Plataforma</th><th scope="col">Sequência</th><th scope="col">SHA-256</th><th scope="col">Estado</th></tr></thead><tbody>
@forelse ($releases as $release)<tr><td data-label="Versão">{{ $release->version }}</td><td data-label="Plataforma">{{ $release->platform }}</td><td data-label="Sequência">{{ $release->sequence }}</td><td data-label="SHA-256" class="mono">{{ $release->sha256 }}</td><td data-label="Estado">{{ app(\App\Agent\ReleaseQuarantine::class)->blocked(['sha256'=>$release->sha256]) ? 'QUARANTINED' : 'AVAILABLE' }}</td></tr>@empty<tr><td colspan="5"><div class="empty"><h3>Nenhuma release publicada</h3><p>As versões publicadas neste tenant aparecerão aqui.</p></div></td></tr>@endforelse
</tbody></table></div></section>
@endsection
