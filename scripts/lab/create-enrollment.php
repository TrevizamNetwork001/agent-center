<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Tenancy\TenantContext::class);$c->apply(platform:true);
try {
 $tenant=App\Models\Tenant::create(['name'=>'RECOVERY-2 LAB','slug'=>'recovery-2-lab','status'=>'active']);
 $user=new App\Models\User;$user->forceFill(['name'=>'Lab owner','email'=>'lab-owner@example.invalid','password'=>Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32))),'tenant_id'=>$tenant->id,'role'=>'tenant_admin','status'=>'active','must_change_password'=>true])->save();
 $c->forUser($user);
 $id=(string)Illuminate\Support\Str::uuid();$code=$id.'.'.bin2hex(random_bytes(32));
 $e=new App\Models\AgentEnrollment;$e->forceFill(['id'=>$id,'tenant_id'=>$tenant->id,'name'=>'Python 0.5.2 LAB','created_by'=>$user->id,'secret_hash'=>hash('sha256',$code),'expires_at'=>now()->addMinutes(15)])->save();
 echo json_encode(['code'=>$code],JSON_THROW_ON_ERROR);
} finally { $c->apply(); }
