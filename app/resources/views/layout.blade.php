<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agent Center</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#101827;color:#e8edf5}body{max-width:1120px;margin:auto;padding:24px}header,nav{display:flex;align-items:center;gap:20px;flex-wrap:wrap}header{justify-content:space-between;border-bottom:1px solid #344155;padding-bottom:18px}a{color:#8bc7ff}main{margin:32px 0}.card{background:#192438;border:1px solid #344155;border-radius:10px;padding:22px;margin:20px 0}label{display:block;margin:12px 0}input,select,button{font:inherit;border:1px solid #526078;border-radius:5px;padding:9px;background:#101827;color:inherit}input,select{display:block;width:min(100%,430px);box-sizing:border-box;margin-top:5px}button{cursor:pointer;background:#225c94}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px 8px;border-bottom:1px solid #344155}.table{overflow:auto}.muted{color:#a8b7ca}.error{border-left:4px solid #fa8585;padding:12px}.success{border-left:4px solid #66d9ac;padding:12px}form.inline{display:inline}code{overflow-wrap:anywhere}
</style></head><body>
<header><strong>Agent Center</strong><nav>@auth
<a href="{{ route('dashboard') }}">Dashboard</a>
@can('viewAny', App\Models\User::class)<a href="{{ route('users.index') }}">Usuários</a>@endcan
@can('viewAny', App\Models\Tenant::class)<a href="{{ route('tenants.index') }}">Tenants</a>@endcan
@can('view-audit')<a href="{{ route('audit.index') }}">Auditoria</a>@endcan
@can('viewAny', App\Models\Agent::class)<a href="{{ route('agents.index') }}">Agents</a>@endcan
<a href="{{ route('password.edit') }}">Senha</a>
<form class="inline" method="post" action="{{ route('logout') }}">@csrf<button>Sair</button></form>
@endauth</nav></header>
<main>@if(session('success'))<p class="success">{{ session('success') }}</p>@endif
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main><footer class="muted">Central · Identidade e auditoria</footer></body></html>
