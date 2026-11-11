<?php

if (getenv('RAG_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Disposable RAG lab fixture only');
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Enums\Role;
use App\Knowledge\Versioning;
use App\Llm\Configuration;
use App\Models\ConversationSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$c = app(TenantContext::class);
$c->apply(platform: true);
$a = Tenant::create(['name' => 'RAG NOC Laboratório A', 'slug' => 'rag-lab-a', 'status' => 'active']);
$b = Tenant::create(['name' => 'RAG Laboratório B', 'slug' => 'rag-lab-b', 'status' => 'active']);
$password = trim(file_get_contents('/lab/password'));
$users = [];
foreach ([[$a, 'admin-a@rag.test'], [$b, 'admin-b@rag.test']] as [$t,$email]) {
    $u = new User;
    $u->forceFill(['name' => $t->name, 'email' => $email, 'password' => Hash::make($password), 'role' => Role::TenantAdmin, 'tenant_id' => $t->id, 'status' => 'active', 'must_change_password' => false])->save();
    $users[] = $u;
}
$c->forUser($users[0]);
$v = app(Versioning::class);
$i = $v->create('knowledge', ['key' => 'noc-lentidao-web', 'title' => 'Procedimento NOC - Lentidão Web', 'category' => 'network', 'scope' => 'tenant', 'tags' => [], 'content' => "# Testes iniciais\nPara investigar um site lento, verificar DNS, conexão TCP e resposta HTTP. Verificar latência e analisar o caminho sem culpar automaticamente um roteador intermediário. Respeitar o escopo autorizado."], $users[0]);
$i = $v->publish($i, $users[0]);
$t = $v->create('prompt', ['key' => 'rag-procedimentos', 'name' => 'Assistência Knowledge', 'purpose' => 'knowledge_assistance', 'system_template' => 'Explique procedimentos somente com as fontes fornecidas.', 'user_template' => 'Pergunta: {{evidence}}\nFontes: {{knowledge}}'], $users[0]);
$t = $v->publish($t, $users[0]);
$conf = app(Configuration::class);
$p = $conf->provider(null, ['name' => 'Fixture determinística de laboratório (sem LLM externo)', 'base_url' => 'https://provider.example.test/v1', 'timeout' => 1, 'enabled' => true, 'api_key' => 'lab-only-fixture-key-no-external-access'], $users[0]);
$m = $conf->model(['provider_id' => $p->id, 'name' => 'Fixture RAG', 'model_identifier' => 'rag-fixture', 'enabled' => true, 'context_limit' => 65536], $users[0]);
$conf->binding(['purpose' => 'knowledge_assistance', 'model_id' => $m->id, 'prompt_id' => $t->id, 'enabled' => true, 'allow_external_context' => true], $users[0]);
$s = new ConversationSetting;
$s->forceFill(['tenant_id' => $a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $users[0]->id])->save();
$conversation = app(Engine::class)->create(Identity::web($users[0]), (string) Str::uuid());
file_put_contents('/lab/demo.json', json_encode(['conversation' => $conversation->id, 'source' => DB::table('rag_chunks')->sole()->id, 'document' => $i->id]));
echo "LAB SEEDED: two tenants; one published document; no Agents or tasks.\n";
