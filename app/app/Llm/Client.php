<?php

namespace App\Llm;

class Client
{
    public function call($provider, $model, array $messages, ?callable $cancelled = null): array
    {
        if ($cancelled && $cancelled()) {
            return ['error' => 'cancelled'];
        }
        if (! $provider->enabled || ! $model->enabled || $model->provider_id !== $provider->id) {
            return ['error' => 'not_configured'];
        }$key = app(SecretVault::class)->decrypt($provider);
        if (! $key) {
            return ['error' => 'secret_unavailable'];
        }
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => str_replace($key, '[REDACTED]', $m['content'])], $messages);
        $outputLimit = min(1024, intdiv($model->context_limit ?? 65536, 2));
        $parameter = $model->config['token_parameter'] ?? 'max_completion_tokens';
        if (! in_array($parameter, ['max_completion_tokens', 'max_tokens'], true)) {
            return ['error' => 'invalid_configuration'];
        }
        $payload = ['model' => $model->model_identifier, 'messages' => $messages, 'stream' => false, 'n' => 1, $parameter => $outputLimit, 'response_format' => ['type' => 'json_object']];
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > min(48000, ($model->context_limit ?? 65536) - $outputLimit)) {
            return ['error' => 'input_too_large'];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $endpoint = app(NetworkPolicy::class)->endpoint($provider->base_url);
                $r = app(CurlTransport::class)->send($endpoint, $key, $payload, $provider->timeout, $cancelled);
            } catch (\Throwable $e) {
                return ['error' => in_array($e->getMessage(), ['ssrf_blocked', 'dns_failure'], true) ? $e->getMessage() : 'transport_failure'];
            }
            $status = $r['status'] ?? 0;
            if (($status === 429 || $status >= 500) && $attempt === 0) {
                usleep(100000);

                continue;
            }if (isset($r['error'])) {
                return ['error' => $r['error']];
            }if ($status !== 200) {
                return ['error' => match (true) {
                    $status === 401 => 'unauthorized',$status === 429 => 'rate_limited',$status >= 500 => 'provider_unavailable',$status >= 300 && $status < 400 => 'redirect_blocked',default => 'provider_rejected'
                }];
            }
            try {
                $json = json_decode($r['body'], true, 24, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['error' => 'invalid_json'];
            }$choice = $json['choices'][0] ?? null;
            if (! is_array($choice) || isset($choice['message']['tool_calls']) || isset($choice['message']['function_call']) || ($choice['finish_reason'] ?? '') !== 'stop' || ! is_string($choice['message']['content'] ?? null)) {
                return ['error' => 'invalid_response'];
            }$output = Output::parse($choice['message']['content']);

            return $output ? ['output' => ['summary' => str_replace($key, '[REDACTED]', $output['summary']), 'explanation' => str_replace($key, '[REDACTED]', $output['explanation']), 'next_steps' => array_map(fn ($s) => str_replace($key, '[REDACTED]', $s), $output['next_steps'])]] : ['error' => 'invalid_output'];
        }

        return ['error' => 'provider_unavailable'];
    }
}
