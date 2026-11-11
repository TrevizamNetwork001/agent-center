<?php

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Llm\Client;
use App\Llm\CurlTransport;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\Diagnostic;
use App\Models\LlmBinding;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Explicit, bounded real API verification; never an HTTP route or automatic test.
if (getenv('NATURAL_LIVE_LAB') !== '1') {
    throw new RuntimeException('Explicit laboratory invocation required');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'agent_center_lab') {
    throw new RuntimeException('Wrong laboratory database');
}
$transport = new class extends CurlTransport
{
    public int $calls = 0;

    public function send(array $endpoint, string $key, array $payload, int $timeout, ?callable $cancelled = null): array
    {
        if ($endpoint['url'] !== 'https://api.openai.com/v1/responses' || ++$this->calls > 1 || isset($payload['tools']) || ($payload['store'] ?? true) !== false || $payload['max_output_tokens'] > 1024) {
            throw new RuntimeException('Live request budget or boundary rejected');
        }

        return parent::send($endpoint, $key, $payload, $timeout, $cancelled);
    }
};
$client = new class extends Client
{
    public array $results = [];

    public function call($provider, $model, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
    {
        $result = parent::call($provider, $model, $messages, $cancelled, $purpose);
        $this->results[] = array_intersect_key($result, array_flip(['error', 'http_status', 'usage', 'request_id', 'attempts', 'duration_ms']));
        $this->results[array_key_last($this->results)]['sanitized_output'] = $result['output'] ?? null;

        return $result;
    }
};
$app->instance(CurlTransport::class, $transport);
$app->instance(Client::class, $client);
$ctx = app(TenantContext::class);
$ctx->apply(userId: 1);
$user = User::findOrFail(1);
$ctx->forUser($user, 1);
$binding = LlmBinding::where('purpose', 'knowledge_assistance')->where('enabled', true)->firstOrFail();
if (($binding->config['allow_external_context'] ?? false) !== true) {
    throw new RuntimeException('Binding does not authorize external context');
}
$previous = $binding->config;
$before = ['tasks' => AgentTask::count(), 'diagnostics' => Diagnostic::count()];
$out = ['environment' => 'isolated agent_center_lab', 'utc' => gmdate('c'), 'real_request_budget' => 1, 'earlier_lab_requests' => (int) (getenv('NATURAL_PRIOR_REQUESTS') ?: 0), 'binding_id' => $binding->id, 'transcript' => []];
try {
    // Explicit lab opt-in on the same binding/model/published prompt; restored below.
    $binding->config = [...$previous, 'allow_conversation_language' => true];
    $binding->save();
    $engine = app(Engine::class);
    $identity = Identity::web($user);
    $conversation = $engine->create($identity, (string) Str::uuid());
    foreach (['Boa tarde, meu consagrado!', 'E você, como é ajudar num plantão tranquilo?'] as $body) {
        $conversation = $engine->send($identity, $conversation, (string) Str::uuid(), $body);
        $message = ConversationMessage::where('conversation_id', $conversation->id)->latest('sequence')->firstOrFail();
        $out['transcript'][] = ['user' => $body, 'assistant' => $message->body, 'language_status' => $message->metadata['language_status'] ?? null, 'language_reason' => $message->metadata['language_reason'] ?? null, 'usage' => $message->metadata['usage'] ?? [], 'request_id' => $message->metadata['request_id'] ?? null];
    }
    $assisted = count(array_filter($out['transcript'], fn ($turn) => $turn['language_status'] === 'ASSISTED'));
    $out['no_operational_side_effects'] = AgentTask::count() === $before['tasks'] && Diagnostic::count() === $before['diagnostics'];
    $out['network_calls'] = $transport->calls;
    $out['openai'] = $client->results;
    $out['status'] = $assisted >= 1 && $out['no_operational_side_effects'] && $transport->calls <= 1 ? 'PASS' : 'BLOCKED';
    $engine->send($identity, $conversation, (string) Str::uuid(), 'Pode encerrar');
} catch (Throwable $e) {
    $out['status'] = 'BLOCKED';
    $out['failure_class'] = get_class($e);
    $out['network_calls'] = $transport->calls;
    $out['openai'] = $client->results;
} finally {
    $binding->config = $previous;
    $binding->save();
    $out['binding_restored'] = $binding->fresh()->config === $previous;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
exit($out['status'] === 'PASS' && $out['binding_restored'] ? 0 : 1);
