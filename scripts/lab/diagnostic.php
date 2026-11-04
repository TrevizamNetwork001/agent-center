<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Tenancy\TenantContext::class);
try {
$c->apply(platform:true);$tenant=App\Models\Tenant::where('slug','recovery-3-lab')->sole();$c->apply(tenantId:$tenant->id);
$user=App\Models\User::withoutGlobalScopes()->where('tenant_id',$tenant->id)->sole();$c->forUser($user);
if(($argv[1]??'create')==='create'){
app(App\Diagnostics\Catalog::class)->ensure();$agent=App\Models\Agent::sole();
$d=app(App\Diagnostics\Engine::class)->create(App\Models\Profile::where('key','PROBLEMA_DNS')->sole(),$agent,'localhost',$user);echo json_encode(['diagnostic_id'=>$d->id,'target'=>'localhost','profile'=>'PROBLEMA_DNS']);
}else{
$rows=App\Models\Diagnostic::all()->map(fn($d)=>['id'=>$d->id,'status'=>$d->status,'conclusion'=>App\Models\DiagnosticConclusion::where('diagnostic_id',$d->id)->first()?->classification,'steps'=>App\Models\DiagnosticStep::where('diagnostic_id',$d->id)->get(['key','status','result'])->toArray()]);echo json_encode($rows,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
}
}finally{$c->apply();}
