<?php
// Builds on the existing knowledge/key restore fixture, with conversation history in the same tenant.
ob_start();require __DIR__.'/llm-restore-fixture.php';$manifest=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
try{$ctx->forUser($u);$setting=new App\Models\ConversationSetting;$setting->forceFill(['tenant_id'=>$t->id,'enabled'=>true,'retention_days'=>30,'updated_by'=>$u->id])->save();$identity=App\Conversation\Identity::web($u);$engine=app(App\Conversation\Engine::class);$conversation=$engine->create($identity,(string)Illuminate\Support\Str::uuid());
// Completed synthetic technical evidence, created by the preceding restore fixture, never a production run.
$conversation->forceFill(['current_diagnostic_id'=>$d->id,'current_profile_id'=>$d->profile_id,'current_target'=>$d->target,'state'=>'DIAGNOSTIC_RUNNING'])->save();$conversation=$engine->sync($identity,$conversation);$conversation=$engine->send($identity,$conversation,(string)Illuminate\Support\Str::uuid(),'Obrigado');$manifest['conversation_id']=$conversation->id;$manifest['message_count']=App\Models\ConversationMessage::where('conversation_id',$conversation->id)->count();$manifest['event_count']=App\Models\ConversationEvent::where('conversation_id',$conversation->id)->count();echo json_encode($manifest,JSON_THROW_ON_ERROR);
}finally{$ctx->apply();}
