<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
if(Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='agent_center_lab')exit(2);
$c=app(App\Tenancy\TenantContext::class);$c->apply(platform:true);$u=App\Models\User::withoutGlobalScopes()->where('email','lab-owner@example.invalid')->sole();$c->forUser($u);
$a=App\Models\Agent::where('name','LAB update executor')->sole();$audit=app(App\Services\Audit::class);
if(($argv[1]??'')==='approve'){
$o=App\Models\AgentOperation::where('agent_id',$a->id)->where('status','PLANNED')->sole();$o->forceFill(['status'=>'APPROVED','approved_by'=>$u->id,'approved_at'=>now()])->save();$audit->record('agent.update_approved',$u,$a->tenant_id,'agent',null,['agent_id'=>$a->id,'operation_id'=>$o->id]);echo "Lab: explicit plan approval recorded\n";
}else{
$kind=$argv[1]??'';if(!in_array($kind,['update','rollback'],true))exit(2);$version=$kind==='update'?'0.5.3':'0.5.2';
$release=collect(app(App\Agent\Releases::class)->all())->firstWhere('version',$version);if(!$release)exit(2);
$o=new App\Models\AgentOperation;$o->forceFill(['id'=>(string)Illuminate\Support\Str::uuid(),'tenant_id'=>$a->tenant_id,'agent_id'=>$a->id,'requested_by'=>$u->id,'kind'=>$kind,'status'=>'PRECHECK_REQUESTED','from_version'=>$a->version,'release'=>$release,'reason'=>'repair'])->save();$audit->record('agent.'.$kind.'_requested',$u,$a->tenant_id,'agent',null,['agent_id'=>$a->id,'operation_id'=>$o->id]);echo "Lab: structured intention created\n";
}
$c->apply();

} catch(Throwable $e) {fwrite(STDERR,"Lab operation fixture failed\n");exit(1);}
