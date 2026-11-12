<?php

namespace App\Llm;

/** Bounded diagnostics, with no endpoint, credential, response body or raw exception text. */
class TransportFailure
{
    public static function classify(int $errno, int $osErrno, float $connectSeconds, float $tlsSeconds, int $verifyResult, bool $oversize = false): array
    {
        $error = $oversize ? 'response_too_large' : match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'timeout', CURLE_COULDNT_RESOLVE_HOST => 'dns_failure',
            CURLE_COULDNT_CONNECT => 'connection_refused', CURLE_ABORTED_BY_CALLBACK => 'cancelled', default => 'transport_failure',
        };
        $stage = match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'dns', CURLE_COULDNT_CONNECT => 'tcp_connect',
            CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM => 'tls_handshake',
            CURLE_ABORTED_BY_CALLBACK => 'cancelled',
            default => $tlsSeconds > 0 ? 'http_transfer' : ($connectSeconds > 0 ? 'tls_handshake' : 'tcp_connect'),
        };
        $category = match ($errno) {
            CURLE_SSL_CACERT => 'tls_certificate_verification_failed',
            CURLE_SSL_CONNECT_ERROR => 'tls_handshake_failed', CURLE_SSL_CERTPROBLEM => 'tls_local_certificate_failed',
            CURLE_COULDNT_CONNECT => in_array($osErrno, [61, 111], true) ? 'tcp_connection_refused' : 'tcp_connection_failed',
            default => $error,
        };

        return ['error' => $error, 'transport_detail' => ['stage' => $stage, 'category' => $category, 'curl_errno' => $errno,
            'os_errno' => $osErrno, 'ssl_verify_result' => $verifyResult,
            'tcp_connected' => $connectSeconds > 0 || $tlsSeconds > 0, 'tls_established' => $tlsSeconds > 0]];
    }
}
