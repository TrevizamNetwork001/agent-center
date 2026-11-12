<?php

$dir = $argv[1];
$config = json_decode(file_get_contents($dir.'/response.json'), true);
$context = stream_context_create(['ssl' => ['local_cert' => $dir.'/cert.pem', 'local_pk' => $dir.'/key.pem', 'verify_peer' => false]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (! $server) {
    exit(2);
}
// Publish only complete readiness data. file_exists() can observe an empty file
// between open/truncate and write; the old reader then selected port 0 (TCP failure).
file_put_contents($dir.'/port.pending', explode(':', stream_socket_get_name($server, false))[1]);
if (($config['publish_delay'] ?? 0) > 0) {
    usleep($config['publish_delay']);
}
rename($dir.'/port.pending', $dir.'/port');
file_put_contents($dir.'/events.jsonl', json_encode(['event' => 'listening'])."\n");
// A rejected TLS handshake must not close the listener before libcurl
// completes its failure path. Keep a bounded listener until test teardown.
$deadline = microtime(true) + 15;
$n = 0;
while ($n < 2 && microtime(true) < $deadline) {
    $conn = @stream_socket_accept($server, min(5, max(0.01, $deadline - microtime(true))));
    if (! $conn) {
        continue;
    }
    file_put_contents($dir.'/events.jsonl', json_encode(['event' => 'tcp_accepted'])."\n", FILE_APPEND);
    stream_set_timeout($conn, 3);
    if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        file_put_contents($dir.'/events.jsonl', json_encode(['event' => 'tls_rejected'])."\n", FILE_APPEND);
        fclose($conn);

        continue;
    }
    file_put_contents($dir.'/events.jsonl', json_encode(['event' => 'tls_established'])."\n", FILE_APPEND);
    $n++;
    stream_set_timeout($conn, 3);
    $length = 0;
    while (($line = fgets($conn)) !== false && trim($line) !== '') {
        if (str_starts_with(strtolower($line), 'content-length:')) {
            $length = (int) trim(explode(':', $line, 2)[1]);
        }
    }$payload = '';
    while (strlen($payload) < $length && ! feof($conn)) {
        $chunk = fread($conn, min(4096, $length - strlen($payload)));
        if ($chunk === '') {
            break;
        }$payload .= $chunk;
    }file_put_contents($dir.'/payload.json', $payload);
    if (($config['delay'] ?? 0) > 0) {
        usleep($config['delay']);
    }$body = $config['body'];
    $status = $config['status'];
    $headers = "HTTP/1.1 $status Test\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n";
    if ($status === 302) {
        $headers .= "Location: https://127.0.0.1/metadata\r\n";
    }@fwrite($conn, $headers."\r\n".$body);
    fclose($conn);
}fclose($server);
