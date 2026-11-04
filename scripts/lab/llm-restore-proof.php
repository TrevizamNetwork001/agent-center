<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=app(App\Tenancy\TenantContext::class);$exit=0;
try{$manifest=json_decode(file_get_contents('/proof/manifest.json'),true,flags:JSON_THROW_ON_ERROR);$c->apply(tenantId:$manifest['tenant_id']);$provider=App\Models\LlmProvider::findOrFail($manifest['provider_id']);$secret=app(App\Llm\SecretVault::class)->decrypt($provider);if(!is_string($secret)||!hash_equals($manifest['expected_sha256'],hash('sha256',$secret)))throw new RuntimeException('Restored secret verification failed');
if(!App\Models\KnowledgeVersion::where('knowledge_item_id',$manifest['knowledge_id'])->where('status','PUBLISHED')->exists())throw new RuntimeException('Published version missing');if(App\Models\PromptVersion::where('status','PUBLISHED')->count()!==1||App\Models\LlmModel::count()!==1||App\Models\LlmBinding::count()!==1)throw new RuntimeException('Foundation records missing');
if(App\Models\InterpretationSource::count()!==1 || !App\Models\DiagnosticInterpretation::whereNotNull('prompt_version_id')->exists())throw new RuntimeException('Provenance missing');
if(isset($manifest['conversation_id'])){
 $conversation=App\Models\Conversation::findOrFail($manifest['conversation_id']);
 if($conversation->state!=='CLOSED'||!$conversation->current_diagnostic_id)throw new RuntimeException('Conversation context not restored');
 if(App\Models\ConversationMessage::where('conversation_id',$conversation->id)->count()!==$manifest['message_count']||App\Models\ConversationEvent::where('conversation_id',$conversation->id)->count()!==$manifest['event_count'])throw new RuntimeException('Conversation history not restored');
}
echo "Isolated restore: data, versions, RLS context and AES-GCM secret PASS\n";
}catch(\Throwable){fwrite(STDERR,"Isolated restore verification FAILED (details withheld)\n");$exit=1;}finally{$c->apply();}
exit($exit);
