<?php

namespace App\Llm;

use App\Rag\Response;

class Client
{
    public function call($provider, $model, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
    {
        $start = hrtime(true);
        $attempts = 0;
        $status = null;
        $usage = [];
        $requestId = null;
        $finish = function (array $result) use ($start, &$attempts, &$status, &$usage, &$requestId): array {
            return $result + ['http_status' => $status, 'duration_ms' => (int) ((hrtime(true) - $start) / 1000000), 'attempts' => $attempts, 'usage' => $usage, 'request_id' => $requestId];
        };
        if ($cancelled && $cancelled()) {
            return $finish(['error' => 'cancelled']);
        }
        if (! $provider->enabled || ! $model->enabled || $model->provider_id !== $provider->id) {
            return $finish(['error' => 'not_configured']);
        }
        $key = app(SecretVault::class)->decrypt($provider);
        if (! $key) {
            return $finish(['error' => 'secret_unavailable']);
        }
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => str_replace($key, '[REDACTED]', $m['content'])], $messages);
        // Keep other compatible providers on their existing protocol. No new provider registry.
        $responses = rtrim($provider->base_url, '/') === 'https://api.openai.com/v1';
        $outputLimit = min(1024, intdiv($model->context_limit ?? 65536, 2));
        if ($responses) {
            $payload = ['model' => $model->model_identifier, 'input' => $messages, 'stream' => false, 'store' => false, 'max_output_tokens' => $outputLimit, 'text' => ['format' => ['type' => 'json_object']]];
            if (preg_match('/^gpt-6[.-]/', $model->model_identifier)) {
                $payload['reasoning'] = ['effort' => 'low'];
            }
        } else {
            $parameter = $model->config['token_parameter'] ?? 'max_completion_tokens';
            if (! in_array($parameter, ['max_completion_tokens', 'max_tokens'], true)) {
                return $finish(['error' => 'invalid_configuration']);
            }
            $payload = ['model' => $model->model_identifier, 'messages' => $messages, 'stream' => false, 'n' => 1, $parameter => $outputLimit, 'response_format' => ['type' => 'json_object']];
        }
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > min(48000, ($model->context_limit ?? 65536) - $outputLimit)) {
            return $finish(['error' => 'input_too_large']);
        }
        // OpenAI calls do not retry automatically: a test has a strict one-request cost bound.
        $maxAttempts = $responses ? 1 : 2;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                // Reuse the exact DNS/allowlist/IP/TLS policy before selecting the Responses path.
                $endpoint = app(NetworkPolicy::class)->endpoint($provider->base_url);
                if ($responses) {
                    $endpoint['url'] = 'https://api.openai.com/v1/responses';
                }
                $attempts++;
                $r = app(CurlTransport::class)->send($endpoint, $key, $payload, $provider->timeout, $cancelled);
            } catch (\Throwable $e) {
                return $finish(['error' => in_array($e->getMessage(), ['ssrf_blocked', 'dns_failure'], true) ? $e->getMessage() : 'transport_failure']);
            }
            $status = $r['status'] ?? null;
            $requestId = is_string($r['request_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $r['request_id']) ? $r['request_id'] : null;
            if (($status === 429 || $status >= 500) && $attempt + 1 < $maxAttempts) {
                usleep(100000);

                continue;
            }
            if (isset($r['error'])) {
                return $finish(['error' => $r['error']]);
            }
            if ($status !== 200) {
                return $finish(['error' => match (true) {
                    $status === 400 => 'invalid_payload',
                    $status === 401 => 'unauthorized',
                    $status === 403 => 'permission_denied',
                    $status === 404 => 'model_or_endpoint_unavailable',
                    $status === 429 => 'rate_limited',
                    $status >= 500 => 'provider_unavailable',
                    $status >= 300 && $status < 400 => 'redirect_blocked',
                    default => 'provider_rejected',
                }]);
            }
            try {
                $json = json_decode($r['body'], true, 24, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $finish(['error' => 'invalid_json']);
            }
            if (! is_array($json)) {
                return $finish(['error' => 'invalid_response']);
            }
            $text = $responses ? $this->responseText($json) : $this->chatText($json);
            foreach ($responses ? ['input_tokens', 'output_tokens', 'total_tokens'] : ['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
                if (isset($json['usage'][$field]) && is_int($json['usage'][$field]) && $json['usage'][$field] >= 0) {
                    $usage[$field] = $json['usage'][$field];
                }
            }
            if ($text === null) {
                return $finish(['error' => 'invalid_response']);
            }
            $output = $purpose === 'knowledge' ? Response::parse($text) : Output::parse($text);
            if (! $output) {
                return $finish(['error' => 'invalid_output']);
            }
            if ($purpose === 'knowledge') {
                return $finish(['output' => ['answer' => str_replace($key, '[REDACTED]', $output['answer']), 'sources' => $output['sources']]]);
            }

            return $finish(['output' => ['summary' => str_replace($key, '[REDACTED]', $output['summary']), 'explanation' => str_replace($key, '[REDACTED]', $output['explanation']), 'next_steps' => array_map(fn ($s) => str_replace($key, '[REDACTED]', $s), $output['next_steps'])]]);
        }

        return $finish(['error' => 'provider_unavailable']);
    }

    private function chatText(array $json): ?string
    {
        $choice = $json['choices'][0] ?? null;
        if (! is_array($choice) || isset($choice['message']['tool_calls']) || isset($choice['message']['function_call']) || ($choice['finish_reason'] ?? '') !== 'stop' || ! is_string($choice['message']['content'] ?? null)) {
            return null;
        }

        return $choice['message']['content'];
    }

    private function responseText(array $json): ?string
    {
        if (($json['status'] ?? '') !== 'completed' || ! empty($json['error']) || ! empty($json['incomplete_details']) || ! is_array($json['output'] ?? null) || ! array_is_list($json['output'])) {
            return null;
        }
        $texts = [];
        foreach ($json['output'] as $item) {
            if (! is_array($item)) {
                return null;
            }
            if (($item['type'] ?? '') === 'reasoning') {
                continue;
            }
            if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant' || ($item['status'] ?? '') !== 'completed' || ! is_array($item['content'] ?? null)) {
                return null;
            }
            foreach ($item['content'] as $part) {
                if (! is_array($part) || ($part['type'] ?? '') !== 'output_text' || ! is_string($part['text'] ?? null) || ! empty($part['annotations'])) {
                    return null;
                }
                $texts[] = $part['text'];
            }
        }

        // Never silently concatenate distinct answers or accept a response containing tool output.
        return count($texts) === 1 ? $texts[0] : null;
    }
}
