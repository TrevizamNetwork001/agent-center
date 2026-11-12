<?php

namespace Tests\Unit;

use App\Llm\TransportFailure;
use PHPUnit\Framework\TestCase;

class TransportFailureTest extends TestCase
{
    public function test_tcp_refusal_is_never_relabelled_as_tls_failure(): void
    {
        $r = TransportFailure::classify(CURLE_COULDNT_CONNECT, 111, 0, 0, 0);
        $this->assertSame('connection_refused', $r['error']);
        $this->assertSame('tcp_connect', $r['transport_detail']['stage']);
        $this->assertSame('tcp_connection_refused', $r['transport_detail']['category']);
        $this->assertFalse($r['transport_detail']['tls_established']);
    }

    public function test_tls_failure_preserves_public_contract_and_specific_internal_cause(): void
    {
        $r = TransportFailure::classify(CURLE_SSL_CACERT, 0, 0.001, 0, 18);
        $this->assertSame('transport_failure', $r['error']);
        $this->assertSame('tls_handshake', $r['transport_detail']['stage']);
        $this->assertSame('tls_certificate_verification_failed', $r['transport_detail']['category']);
        $this->assertTrue($r['transport_detail']['tcp_connected']);
        $this->assertSame(18, $r['transport_detail']['ssl_verify_result']);
        $this->assertArrayNotHasKey('url', $r['transport_detail']);
        $this->assertArrayNotHasKey('message', $r['transport_detail']);
    }

    public function test_timeout_stage_and_existing_error_categories_are_preserved(): void
    {
        $this->assertSame('http_transfer', TransportFailure::classify(CURLE_OPERATION_TIMEDOUT, 0, 0.001, 0.002, 0)['transport_detail']['stage']);
        $this->assertSame('timeout', TransportFailure::classify(CURLE_OPERATION_TIMEDOUT, 0, 0, 0, 0)['error']);
        $this->assertSame('dns_failure', TransportFailure::classify(CURLE_COULDNT_RESOLVE_HOST, 0, 0, 0, 0)['error']);
        $this->assertSame('cancelled', TransportFailure::classify(CURLE_ABORTED_BY_CALLBACK, 0, 0, 0, 0)['error']);
        $this->assertSame('response_too_large', TransportFailure::classify(CURLE_WRITE_ERROR, 0, 0.001, 0.002, 0, true)['error']);
    }
}
