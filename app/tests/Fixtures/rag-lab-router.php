<?php

use App\Llm\CurlTransport;
use App\Llm\DnsResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

if (getenv('RAG_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Disposable RAG lab fixture only');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file('/var/www/html/public'.$path)) {
    return false;
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance(DnsResolver::class, new class extends DnsResolver
{
    public function resolve(string $h): array
    {
        return ['93.184.216.34'];
    }
});
$app->instance(CurlTransport::class, new class extends CurlTransport
{
    public function send(array $endpoint, string $key, array $payload, int $timeout, ?callable $cancelled = null): array
    {
        $data = json_decode($payload['messages'][1]['content'], true);
        $sources = $data['excerpts'] ?? [];
        $output = ['answer' => 'Para investigar um site lento, verifique DNS, conexão TCP, resposta HTTP e latência. Analise o caminho sem culpar automaticamente um roteador intermediário. Esses passos são procedimentos documentados; nenhuma sondagem foi executada.', 'sources' => array_column($sources, 'id')];

        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output)]]]])];
    }
});
$app->handleRequest(Request::capture());
