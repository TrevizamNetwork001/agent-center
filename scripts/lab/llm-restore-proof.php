<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Tenancy\TenantContext::class);$manifest=json_decode(file_get_contents('/proof/manifest.json'),true,flags:JSON_THROW_ON_ERROR);
try{$c->apply(tenantId:$manifest['tenant_id']);$provider=App\Models\LlmProvider::findOrFail($manifest['provider_id']);$secret=app(App\Llm\SecretVault::class)->decrypt($provider);if(!is_string($secret)||!hash_equals($manifest['expected_sha256'],hash('sha256',$secret)))throw new RuntimeException('Restored secret verification failed');
if(!App\Models\KnowledgeVersion::where('knowledge_item_id',$manifest['knowledge_id'])->where('status','PUBLISHED')->exists())throw new RuntimeException('Published version missing');if(App\Models\PromptVersion::where('status','PUBLISHED')->count()!==1||App\Models\LlmModel::count()!==1||App\Models\LlmBinding::count()!==1)throw new RuntimeException('Foundation records missing');
if(App\Models\InterpretationSource::count()!==1 || !App\Models\DiagnosticInterpretation::whereNotNull('prompt_version_id')->exists())throw new RuntimeException('Provenance missing');
echo "Isolated restore: data, versions, RLS context and AES-GCM secret PASS\n";
}finally{$c->apply();}
