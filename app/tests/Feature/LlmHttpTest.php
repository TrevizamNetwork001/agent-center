<?php

namespace Tests\Feature;

use App\Llm\Client;
use App\Llm\CurlTransport;
use App\Llm\NetworkPolicy;
use Tests\IntelligenceTestCase;

class LlmHttpTest extends IntelligenceTestCase
{
    private string $dir;

    private $process = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/llm-http-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir.'/openssl.cnf', "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=DNS:provider.example.test\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,digitalSignature,keyEncipherment\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'provider.example.test'], $key, ['config' => $this->dir.'/openssl.cnf']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $this->dir.'/openssl.cnf', 'x509_extensions' => 'ext']);
        openssl_pkey_export($key, $private);
        openssl_x509_export($cert, $public);
        file_put_contents($this->dir.'/key.pem', $private);
        file_put_contents($this->dir.'/cert.pem', $public);
        chmod($this->dir.'/key.pem', 0600);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        foreach (glob($this->dir.'/*') as $f) {
            unlink($f);
        }rmdir($this->dir);
        parent::tearDown();
    }

    private function stopServer(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function server(int $status, string $body, int $delay = 0): int
    {
        $this->stopServer();
        @unlink($this->dir.'/port');
        file_put_contents($this->dir.'/response.json', json_encode(compact('status', 'body', 'delay')));
        $this->process = proc_open([PHP_BINARY, base_path('tests/Fixtures/llm-server.php'), $this->dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 3;
        while (! file_exists($this->dir.'/port')) {
            if (microtime(true) > $deadline) {
                $this->fail('Local TLS fake did not start');
            }usleep(10000);
        }$port = (int) file_get_contents($this->dir.'/port');
        $this->endpoint($port);
        $this->app->instance(CurlTransport::class, new CurlTransport($this->dir.'/cert.pem'));

        return $port;
    }

    private function endpoint(int $port): void
    {
        $this->app->instance(NetworkPolicy::class, new class($port) extends NetworkPolicy
        {
            public function __construct(private int $port) {}

            public function endpoint(string $url): array
            {
                return ['url' => 'https://provider.example.test:'.$this->port.'/v1/chat/completions', 'host' => 'provider.example.test', 'port' => $this->port, 'ip' => '127.0.0.1'];
            }
        });
    }

    public function test_real_tls_fake_success_and_minimal_payload(): void
    {
        $this->server(200, json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"summary":"ok","explanation":"fixture","next_steps":[]}']]]]));
        $p = $this->provider();
        $m = $this->model($p);
        $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'Minimal test']]);
        $this->assertSame('ok', $r['output']['summary'] ?? $r['error'] ?? 'missing');
        $payload = json_decode(file_get_contents($this->dir.'/payload.json'), true);
        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayHasKey('max_completion_tokens', $payload);
        $this->assertArrayNotHasKey('api_key', $payload);
    }

    public function test_real_tls_fake_statuses_invalid_json_and_size_limits(): void
    {
        $p = $this->provider();
        $m = $this->model($p);
        foreach ([[401, '{}', 'unauthorized'], [429, '{}', 'rate_limited'], [500, '{}', 'provider_unavailable'], [302, '{}', 'redirect_blocked'], [200, '{bad', 'invalid_json'], [200, '{}', 'invalid_response'], [200, str_repeat('x', 40000), 'response_too_large']] as [$code,$body,$error]) {
            $this->server($code, $body);
            $r = app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'test']]);
            $this->assertSame($error, $r['error'] ?? 'unexpected_success');
        }
    }

    public function test_real_timeout_connection_refused_tls_failure_and_cancellation(): void
    {
        $p = $this->provider();
        $m = $this->model($p);
        $port = $this->server(200, '{}', 1800000);
        $this->assertSame('timeout', app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'test']])['error']);
        $this->stopServer();
        $this->endpoint($port);
        $this->assertSame('connection_refused', app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'test']])['error']);
        $this->server(200, '{}');
        $this->app->instance(CurlTransport::class, new CurlTransport);
        $this->assertSame('transport_failure', app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'test']])['error']);
        $this->assertSame('cancelled', app(Client::class)->call($p, $m, [['role' => 'user', 'content' => 'test']], fn () => true)['error']);
    }
}
