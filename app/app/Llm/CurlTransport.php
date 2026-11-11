<?php

namespace App\Llm;

class CurlTransport
{
    public function __construct(private ?string $caFile = null) {}

    public function send(array $endpoint, string $key, array $payload, int $timeout, ?callable $cancelled = null): array
    {
        if ($cancelled && $cancelled()) {
            return ['error' => 'cancelled'];
        }$body = '';
        $oversize = false;
        $requestId = null;
        $c = curl_init($endpoint['url']);
        $ip = str_contains($endpoint['ip'], ':') ? '['.$endpoint['ip'].']' : $endpoint['ip'];
        $opts = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Content-Type: application/json', 'Accept: application/json'], CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeout * 1000), CURLOPT_TIMEOUT_MS => $timeout * 1000, CURLOPT_RESOLVE => [$endpoint['host'].':'.$endpoint['port'].':'.$ip], CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => fn () => ($cancelled && $cancelled()) ? 1 : 0, CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$oversize) {
            if (strlen($body) + strlen($chunk) > 32768) {
                $oversize = true;

                return 0;
            }$body .= $chunk;

            return strlen($chunk);
        }];
        $opts[CURLOPT_HEADERFUNCTION] = function ($curl, string $line) use (&$requestId): int {
            if (preg_match('/^x-request-id:\s*([A-Za-z0-9_-]{1,128})\s*$/iD', trim($line), $matches)) {
                $requestId = $matches[1];
            }

            return strlen($line);
        };
        if ($this->caFile) {
            $opts[CURLOPT_CAINFO] = $this->caFile;
        }curl_setopt_array($c, $opts);
        $ok = curl_exec($c);
        $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($c);
        curl_close($c);
        if ($ok === false) {
            return ['error' => $oversize ? 'response_too_large' : match ($error) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',CURLE_COULDNT_RESOLVE_HOST => 'dns_failure',CURLE_COULDNT_CONNECT => 'connection_refused',CURLE_ABORTED_BY_CALLBACK => 'cancelled',default => 'transport_failure'
            }];
        }

        return ['status' => $status, 'body' => $body, 'request_id' => $requestId];
    }
}
