<?php

namespace Tests\Feature;

use App\Llm\Client;
use App\Llm\CurlTransport;
use App\Llm\DnsResolver;
use App\Models\AgentTask;
use Illuminate\Support\Str;
use Tests\IntelligenceTestCase;

class OpenAiResponsesTest extends IntelligenceTestCase
{
    private function configure(): array
    {
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $p = $this->provider(['base_url' => 'https://api.openai.com/v1', 'timeout' => 3]);
        $m = $this->model($p);
        $m->forceFill(['model_identifier' => 'gpt-6.1-sol'])->save();
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function resolve(string $h): array
            {
                return ['93.184.216.34'];
            }
        });

        return [$p, $m];
    }

    private function transport(array $result): void
    {
        $this->app->instance(CurlTransport::class, new class($result) extends CurlTransport
        {
            public array $calls = [];

            public function __construct(private array $result) {}

            public function send(array $endpoint, string $key, array $payload, int $timeout, ?callable $cancelled = null): array
            {
                $this->calls[] = compact('endpoint', 'payload', 'timeout');

                return $this->result;
            }
        });
    }

    private function envelope(string $text): array
    {
        return ['status' => 'completed', 'error' => null, 'incomplete_details' => null, 'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
        ], 'usage' => ['input_tokens' => 90, 'output_tokens' => 42, 'total_tokens' => 132]];
    }

    private function success(array $data): array
    {
        return ['status' => 200, 'body' => json_encode($this->envelope(json_encode($data)))];
    }

    public function test_openai_responses_payload_and_metrics_without_tools_or_sampling_parameters(): void
    {
        [$p,$m] = $this->configure();
        $this->transport($this->success(['summary' => 'ok', 'explanation' => 'Synthetic procedure only.', 'next_steps' => []]));
        $r = app(Client::class)->call($p, $m, [['role' => 'system', 'content' => 'Return JSON.'], ['role' => 'user', 'content' => 'Tiny synthetic test.']]);
        $this->assertSame('ok', $r['output']['summary']);
        $this->assertSame(200, $r['http_status']);
        $this->assertSame(1, $r['attempts']);
        $this->assertSame(['input_tokens' => 90, 'output_tokens' => 42, 'total_tokens' => 132], $r['usage']);
        $this->assertGreaterThanOrEqual(0, $r['duration_ms']);
        $c = app(CurlTransport::class)->calls[0];
        $this->assertSame('https://api.openai.com/v1/responses', $c['endpoint']['url']);
        $this->assertSame('api.openai.com', $c['endpoint']['host']);
        $this->assertSame(3, $c['timeout']);
        $payload = $c['payload'];
        $this->assertFalse($payload['store']);
        $this->assertFalse($payload['stream']);
        $this->assertSame(['effort' => 'low'], $payload['reasoning']);
        $this->assertSame(1024, $payload['max_output_tokens']);
        $this->assertSame(['format' => ['type' => 'json_object']], $payload['text']);
        foreach (['temperature', 'top_p', 'tools', 'tool_choice', 'messages', 'response_format', 'n', 'max_completion_tokens', 'api_key', 'previous_response_id'] as $k) {
            $this->assertArrayNotHasKey($k, $payload);
        }
    }

    public function test_rag_response_contract_keeps_exact_source_identifiers(): void
    {
        [$p,$m] = $this->configure();
        $id = (string) Str::uuid();
        $this->transport($this->success(['answer' => 'Synthetic grounded answer.', 'sources' => [$id]]));
        $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'Return JSON with answer and sources.']], null, 'knowledge');
        $this->assertSame([$id], $r['output']['sources']);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_http_error_categories_and_openai_cost_bound_without_retries(): void
    {
        [$p,$m] = $this->configure();
        foreach ([400 => 'invalid_payload', 401 => 'unauthorized', 403 => 'permission_denied', 404 => 'model_or_endpoint_unavailable', 429 => 'rate_limited', 503 => 'provider_unavailable'] as $status => $error) {
            $this->transport(['status' => $status, 'body' => 'Do not log this body.']);
            $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'JSON test']]);
            $this->assertSame($error, $r['error']);
            $this->assertSame($status, $r['http_status']);
            $this->assertSame(1, $r['attempts']);
            $this->assertCount(1, app(CurlTransport::class)->calls);
            $this->assertStringNotContainsString('Do not log', json_encode($r));
        }
    }

    public function test_incomplete_refusal_and_tool_outputs_fail_closed(): void
    {
        [$p,$m] = $this->configure();
        $valid = $this->envelope('{"summary":"ok","explanation":"test","next_steps":[]}');
        $incomplete = $valid;
        $incomplete['status'] = 'incomplete';
        $incomplete['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $refusal = $valid;
        $refusal['output'][1]['content'] = [['type' => 'refusal', 'refusal' => 'refused']];
        $tools = $valid;
        $tools['output'][] = ['type' => 'function_call', 'name' => 'shell', 'arguments' => '{}'];
        $search = $valid;
        $search['output'][] = ['type' => 'web_search_call', 'status' => 'completed'];
        $citations = $valid;
        $citations['output'][1]['content'][0]['annotations'] = [['type' => 'url_citation', 'url' => 'https://invented.invalid']];
        foreach ([$incomplete, $refusal, $tools, $search, $citations] as $data) {
            $this->transport(['status' => 200, 'body' => json_encode($data)]);
            $this->assertSame('invalid_response', app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'JSON test']])['error']);
        }
    }

    public function test_malformed_response_and_schema_are_not_reported_as_success(): void
    {
        [$p,$m] = $this->configure();
        foreach ([['status' => 200, 'body' => 'null'], ['status' => 200, 'body' => '{bad'], ['status' => 200, 'body' => json_encode($this->envelope('{"unknown":true}'))]] as $i => $r) {
            $this->transport($r);
            $out = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'JSON test']]);
            $this->assertSame(['invalid_response', 'invalid_json', 'invalid_output'][$i], $out['error']);
        }
    }

    public function test_timeout_and_cancellation_have_no_retry_or_unbounded_usage(): void
    {
        [$p,$m] = $this->configure();
        $this->transport(['error' => 'timeout']);
        $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'JSON test']]);
        $this->assertSame('timeout', $r['error']);
        $this->assertSame(1, $r['attempts']);
        $this->assertSame([], $r['usage']);
        $r = app(Client::class)->call($p, $m, [], fn () => true);
        $this->assertSame('cancelled', $r['error']);
        $this->assertSame(0, $r['attempts']);
        $this->assertCount(1, app(CurlTransport::class)->calls);
    }

    public function test_ssrf_allowlist_and_public_dns_validation_still_run_before_dispatch(): void
    {
        [$p,$m] = $this->configure();
        $this->transport(['status' => 200, 'body' => '{}']);
        config(['llm.allowed_hosts' => []]);
        $this->assertSame('ssrf_blocked', app(Client::class)->call($p, $m, [])['error']);
        config(['llm.allowed_hosts' => ['api.openai.com']]);
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function resolve(string $h): array
            {
                return ['127.0.0.1'];
            }
        });
        $this->assertSame('ssrf_blocked', app(Client::class)->call($p, $m, [])['error']);
        $this->assertCount(0, app(CurlTransport::class)->calls);
    }

    public function test_plain_model_output_cannot_leak_the_configured_key(): void
    {
        [$p,$m] = $this->configure();
        $this->transport($this->success(['summary' => 'fixture-provider-secret-value', 'explanation' => 'ok', 'next_steps' => []]));
        $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'fixture-provider-secret-value JSON']]);
        $this->assertStringNotContainsString('fixture-provider-secret-value', json_encode($r));
        $this->assertStringNotContainsString('fixture-provider-secret-value', json_encode(app(CurlTransport::class)->calls));
    }
}
