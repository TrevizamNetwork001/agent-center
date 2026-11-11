<?php

use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Enums\Role;
use App\Knowledge\Versioning;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\ConversationSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (getenv('NATURAL_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Disposable lab only');
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'natural_conversation_lab') {
    throw new RuntimeException('Wrong database');
}
$ctx = app(TenantContext::class);
$ctx->apply(platform: true);
$users = [];
foreach (['a', 'b'] as $suffix) {
    $tenant = Tenant::create(['name' => 'Conversa Natural · LAB '.strtoupper($suffix), 'slug' => 'natural-lab-'.$suffix, 'status' => 'active']);
    $u = new User;
    $u->forceFill(['name' => 'Operador LAB '.strtoupper($suffix), 'email' => 'admin-'.$suffix.'@natural.test', 'password' => Hash::make(trim(file_get_contents('/lab/password'))), 'role' => Role::TenantAdmin, 'tenant_id' => $tenant->id, 'status' => 'active', 'must_change_password' => false])->save();
    $users[] = $u;
}
foreach ($users as $u) {
    $ctx->forUser($u);
    $setting = new ConversationSetting;
    $setting->forceFill(['tenant_id' => $u->tenant_id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $u->id])->save();
}
$u = $users[0];
$ctx->forUser($u);
app(Catalog::class)->ensure();
$agent = new Agent;
$agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $u->tenant_id, 'name' => 'Agent sintético · sem runtime ou equipamento', 'hostname' => 'synthetic-lab', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()])->save();
$versioning = app(Versioning::class);
$document = $versioning->create('knowledge', ['key' => 'natural-procedimento', 'title' => 'Procedimento LAB · lentidão', 'category' => 'network', 'scope' => 'tenant', 'tags' => [], 'content' => '# Lentidão LAB: comparar DNS, TCP e HTTPS com evidências antes de atribuir uma causa.'], $u);
$versioning->publish($document, $u);
$config = app(Configuration::class);
$provider = $config->provider(null, ['name' => 'Fixture determinística LAB · sem inferência externa', 'base_url' => 'https://provider.example.test/v1', 'timeout' => 1, 'enabled' => true, 'api_key' => 'natural-lab-fixture-not-real-key'], $u);
$model = $config->model(['provider_id' => $provider->id, 'name' => 'Fixture linguagem/RAG', 'model_identifier' => 'natural-lab', 'enabled' => true, 'context_limit' => 65536], $u);
foreach (['diagnostic_explanation', 'knowledge_assistance'] as $purpose) {
    $prompt = $versioning->create('prompt', ['key' => 'natural-'.$purpose, 'name' => 'Prompt publicado LAB', 'purpose' => $purpose, 'system_template' => 'Contexto de apresentação: {{classification}}.', 'user_template' => 'Dados: {{evidence}} Fontes: {{knowledge}}'], $u);
    $prompt = $versioning->publish($prompt, $u);
    $config->binding(['purpose' => $purpose, 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => $purpose === 'diagnostic_explanation'], $u);
}
$engine = app(Engine::class);
$identity = Identity::web($u);
$out = [];
foreach (['social', 'technical', 'confirmation', 'failure', 'knowledge'] as $key) {
    $out[$key] = $engine->create($identity, (string) Str::uuid(), $agent->id)->id;
}
file_put_contents('/lab/demo.json', json_encode($out, JSON_PRETTY_PRINT));
echo "LAB SEEDED: two tenants, published bindings/Knowledge, synthetic Agent only.\n";
