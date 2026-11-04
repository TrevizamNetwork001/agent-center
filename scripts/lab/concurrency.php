<?php
// Dedicated lab DB only. Child arguments contain IDs and private file paths, never tokens.
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Tenant,User,Agent,Profile,Diagnostic,AgentTask,AgentTaskAttempt,DiagnosticPlan};
use App\Diagnostics\{Catalog,Engine};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
$c=app(TenantContext::class);$engine=app(Engine::class);
if(DB::connection()->getDatabaseName()!=='agent_center_lab')throw new RuntimeException('Lab database required');
$mode=$argv[1]??'parent';
if($mode!=='parent'){
$c->apply(tenantId:(int)$argv[2]);$agent=Agent::findOrFail($argv[3]);$barrier=$argv[4];$deadline=microtime(true)+10;while(!is_file($barrier)){if(microtime(true)>$deadline)exit(2);usleep(1000);}
if($mode==='claim'){$t=$engine->claim($agent);echo json_encode(['task_id'=>$t['task_id']??null]);}
elseif($mode==='tick'){$engine->tick();echo json_encode(['ok'=>true]);}
else {$e=json_decode(file_get_contents($argv[5]),true,flags:JSON_THROW_ON_ERROR);echo json_encode($engine->operation($agent,'result',$e));}
$c->apply();exit;
}
function parallel(string $mode,int $tenant,string $agent,?array $envelope=null):array{
$dir=sys_get_temp_dir().'/diag-concurrency-'.bin2hex(random_bytes(8));mkdir($dir,0700);$barrier=$dir.'/go';$private=$dir.'/payload';if($envelope){file_put_contents($private,json_encode($envelope));chmod($private,0600);}
$processes=[];
for($i=0;$i<2;$i++){$command=[PHP_BINARY,__FILE__,$mode,(string)$tenant,$agent,$barrier,$private];$p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$processes[]=[$p,$pipes];}
touch($barrier);$results=[];foreach($processes as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0)throw new RuntimeException('Concurrency child failed (details withheld)');$results[]=json_decode($out,true,flags:JSON_THROW_ON_ERROR);}if(is_file($private))unlink($private);unlink($barrier);rmdir($dir);return $results;
}
try{

$c->apply(platform:true);$tenant=Tenant::create(['name'=>'Concurrent fixture','slug'=>'concurrent-'.bin2hex(random_bytes(8)),'status'=>'active']);
$user=new User;$user->forceFill(['name'=>'Concurrency fixture','email'=>bin2hex(random_bytes(8)).'@example.invalid','password'=>Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32))),'tenant_id'=>$tenant->id,'role'=>'tenant_admin','status'=>'active','must_change_password'=>true])->save();$c->forUser($user);
$agent=new Agent;$agent->forceFill(['id'=>(string)Illuminate\Support\Str::uuid(),'tenant_id'=>$tenant->id,'name'=>'Concurrency fixture','hostname'=>'concurrent-lab','version'=>'0.5.2','status'=>'active','capabilities'=>App\Agent\Protocol::CAPABILITIES,'metadata'=>['task_protocol'=>1],'token_hash'=>hash('sha256',random_bytes(32)),'enrolled_at'=>now()])->save();app(Catalog::class)->ensure();
// Pause only the lab scheduler; the real lab Agent belongs to a different tenant.
if(AgentTask::whereIn('status',['CLAIMED','RUNNING','QUEUED'])->exists())throw new RuntimeException('Unfinished lab tasks');
$d=$engine->create(Profile::where('key','PROBLEMA_DNS')->sole(),$agent,'localhost',$user);
parallel('tick',$tenant->id,$agent->id);if(DiagnosticPlan::where('diagnostic_id',$d->id)->count()!==1||AgentTask::where('diagnostic_id',$d->id)->count()!==2)throw new RuntimeException('Worker retry materialization');
$results=parallel('claim',$tenant->id,$agent->id);if(count(array_filter(array_column($results,'task_id')))!==1)throw new RuntimeException('Double claim');
// Expire the non-started claim so the parent can obtain its own single-use lease.
AgentTaskAttempt::where('status','CLAIMED')->update(['lease_expires_at'=>now()->subSecond()]);$engine->tick();$task=$engine->claim($agent);$e=array_intersect_key($task,array_flip(['task_id','execution_id','generation','lease_token']));$engine->operation($agent,'start',$e);
$e['result']=['ok'=>true,'data'=>['answers'=>['127.0.0.1'],'record_type'=>$task['input']['record_type'],'duration_ms'=>1]];
$results=parallel('result',$tenant->id,$agent->id,$e);if(count(array_filter($results,fn($r)=>($r['duplicate']??false)))!==1)throw new RuntimeException('Duplicate result not idempotent');
$engine->cancel($d,$user);$engine->tick();echo json_encode(['parallel_claim'=>'PASS','parallel_worker'=>'PASS','parallel_duplicate_result'=>'PASS','diagnostic_status'=>$d->fresh()->status],JSON_PRETTY_PRINT).PHP_EOL;
}finally{$c->apply();}
