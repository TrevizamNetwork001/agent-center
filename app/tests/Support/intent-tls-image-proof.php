<?php

use App\Llm\CurlTransport;

if (getenv('INTENT_COMPAT_LAB') !== '1') {
    throw new RuntimeException('LAB only');
}
require '/var/www/html/vendor/autoload.php';
$dir = sys_get_temp_dir().'/compat-tls-'.bin2hex(random_bytes(6));
mkdir($dir, 0700);
file_put_contents($dir.'/openssl.cnf', "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=DNS:provider.example.test\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,digitalSignature,keyEncipherment\n");
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => 'provider.example.test'], $key, ['config' => $dir.'/openssl.cnf']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['config' => $dir.'/openssl.cnf', 'x509_extensions' => 'ext']);
openssl_pkey_export($key, $private);
openssl_x509_export($cert, $public);
file_put_contents($dir.'/key.pem', $private);
chmod($dir.'/key.pem', 0600);
file_put_contents($dir.'/cert.pem', $public);
file_put_contents($dir.'/response.json', json_encode(['status' => 200, 'body' => '{}', 'delay' => 0, 'publish_delay' => 250000]));
$process = proc_open([PHP_BINARY, '/fixture-server.php', $dir], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    $deadline = microtime(true) + 3;
    while (! file_exists($dir.'/port')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('TLS fixture readiness failed');
        }
        usleep(1000);
    }
    $port = (int) file_get_contents($dir.'/port');
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid fixture endpoint');
    }
    $endpoint = ['url' => 'https://provider.example.test:'.$port.'/v1/chat/completions', 'host' => 'provider.example.test', 'port' => $port, 'ip' => '127.0.0.1'];
    $ok = (new CurlTransport($dir.'/cert.pem'))->send($endpoint, 'synthetic-fixture-key', [], 2);
    if (($ok['status'] ?? null) !== 200) {
        throw new RuntimeException('Trusted TLS failed');
    }
    $tls = (new CurlTransport)->send($endpoint, 'synthetic-fixture-key', [], 2);
    if (($tls['error'] ?? null) !== 'transport_failure' || ($tls['transport_detail']['stage'] ?? null) !== 'tls_handshake' || ($tls['transport_detail']['curl_errno'] ?? null) !== CURLE_SSL_CACERT) {
        throw new RuntimeException('TLS verification cause lost');
    }
    proc_terminate($process);
    proc_close($process);
    $process = null;
    $tcp = (new CurlTransport)->send($endpoint, 'synthetic-fixture-key', [], 2);
    if (($tcp['error'] ?? null) !== 'connection_refused' || ($tcp['transport_detail']['stage'] ?? null) !== 'tcp_connect') {
        throw new RuntimeException('TCP failure misclassified');
    }
    file_put_contents('/proof/tls-image.json', json_encode(['status' => 'PASS', 'network' => 'container loopback; network namespace disabled', 'trusted_tls' => 200, 'untrusted_tls' => $tls, 'closed_tcp_listener' => $tcp, 'atomic_publication_delay_us' => 250000], JSON_PRETTY_PRINT));
    echo "Candidate image TLS PASS: trusted handshake, certificate rejection, TCP refusal, exact internal causes.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach (glob($dir.'/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
