<?php
// Only a dedicated lab database; synthetic evidence for UI validation, no network probes.
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Tenant,User,Agent,Profile,Diagnostic};
use App\Tenancy\TenantContext;
use App\Diagnostics\{Catalog,Engine};
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
if(DB::connection()->getDatabaseName()!=='agent_center_lab')throw new RuntimeException('Lab only');
$c=app(TenantContext::class);try{$c->apply(platform:true);$t=Tenant::create(['name'=>'Laboratório UX · fixtures','slug'=>'recovery4-ux-lab','status'=>'active']);$password=bin2hex(random_bytes(24));$u=new User;$u->forceFill(['name'=>'Operação · laboratório','email'=>'ux-lab@example.invalid','password'=>Hash::make($password),'tenant_id'=>$t->id,'role'=>'tenant_admin','status'=>'active','must_change_password'=>false])->save();$c->forUser($u);app(Catalog::class)->ensure();
$id=(string)Str::uuid();$token=$id.'.'.bin2hex(random_bytes(32));$a=new Agent;$a->forceFill(['id'=>$id,'tenant_id'=>$t->id,'name'=>'LAB · São Paulo','hostname'=>'lab-sp-01','status'=>'active','version'=>'0.5.2','token_hash'=>hash('sha256',$token),'capabilities'=>['agent_info','dns_lookup:v1','tcp_connect:v1','http_probe:v1'],'metadata'=>['task_protocol'=>1],'enrolled_at'=>now()->subDays(3),'last_heartbeat_at'=>now(),'last_poll_at'=>now()])->save();
$b=new Agent;$b->forceFill(['id'=>(string)Str::uuid(),'tenant_id'=>$t->id,'name'=>'LAB · Observação secundária','hostname'=>'lab-secondary-02','status'=>'active','version'=>'0.5.2','token_hash'=>hash('sha256',random_bytes(32)),'capabilities'=>['agent_info','dns_lookup:v1'],'metadata'=>['task_protocol'=>1],'enrolled_at'=>now()->subDays(3),'last_heartbeat_at'=>now()->subMinutes(12),'last_poll_at'=>now()->subMinutes(12)])->save();
$engine=app(Engine::class);$normal=null;
foreach([['SITE_LENTO','portal.fixture.test',false],['SITE_NAO_ABRE','servico.fixture.test',true],['PROBLEMA_DNS','dns.fixture.test',false],['CONTEUDO_LENTO','conteudo.fixture.test',false]] as [$key,$target,$negative]){
$d=$engine->create(Profile::where('key',$key)->sole(),$a,$target,$u);$engine->tick();for($i=0;$i<20&&in_array($d->fresh()->status,['PENDING','RUNNING']);$i++){$task=$engine->claim($a);if(!$task){$engine->tick();continue;}$env=array_intersect_key($task,array_flip(['task_id','execution_id','generation','lease_token']));$engine->operation($a,'start',$env);$result=match($task['capability']){'dns_lookup:v1'=>['ok'=>true,'data'=>['answers'=>$task['input']['record_type']==='A'?['127.0.0.1']:[],'record_type'=>$task['input']['record_type'],'duration_ms'=>4]],'tcp_connect:v1'=>['ok'=>true,'data'=>['connected'=>true,'duration_ms'=>12]],default=>['ok'=>true,'data'=>['status'=>$negative?503:200,'tls'=>true,'duration_ms'=>$negative?2650:110,'resolved_ip'=>'127.0.0.1']]};$engine->operation($a,'result',$env+['result'=>$result]);$engine->tick();}if(!$negative&&!$normal)$normal=$d->id;
}
$progress=$engine->create(Profile::where('key','SITE_LENTO')->sole(),$a,'investigacao.fixture.test',$u);$engine->tick();$task=$engine->claim($a);$env=array_intersect_key($task,array_flip(['task_id','execution_id','generation','lease_token']));$engine->operation($a,'start',$env);
echo json_encode(['email'=>$u->email,'password'=>$password,'agent_id'=>$a->id,'token'=>$token,'lease'=>$env,'progress_id'=>$progress->id,'result_id'=>$normal],JSON_THROW_ON_ERROR);
}finally{$c->apply();}
