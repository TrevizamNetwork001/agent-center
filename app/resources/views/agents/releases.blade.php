<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Releases assinadas</title>
<body><h1>Releases assinadas</h1>
@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
<form method="post" enctype="multipart/form-data" action="{{ route('agents.releases.store') }}">@csrf
<label>Manifesto assinado <textarea name="manifest" required maxlength="65536"></textarea></label>
<label>Assinatura Ed25519 em base64 <input name="signature" required maxlength="128"></label>
<label>Artefato <input type="file" name="artifact" required></label><button>Publicar release</button></form>
<table><thead><tr><th>Versão</th><th>Plataforma</th><th>Sequência</th><th>SHA-256</th><th>Estado</th></tr></thead><tbody>
@foreach ($releases as $release)<tr><td>{{ $release->version }}</td><td>{{ $release->platform }}</td><td>{{ $release->sequence }}</td><td>{{ $release->sha256 }}</td><td>{{ app(\App\Agent\ReleaseQuarantine::class)->blocked(['sha256'=>$release->sha256]) ? 'QUARANTINED' : 'AVAILABLE' }}</td></tr>@endforeach
</tbody></table></body></html>
