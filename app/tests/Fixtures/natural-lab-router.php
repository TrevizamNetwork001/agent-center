<?php

use App\Llm\CurlTransport;
use App\Llm\DnsResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

if (getenv('NATURAL_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Disposable natural-conversation lab only');
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
    public function resolve(string $host): array
    {
        return ['93.184.216.34'];
    }
});
$app->instance(CurlTransport::class, new class extends CurlTransport
{
    public function send(array $endpoint, string $key, array $payload, int $timeout, ?callable $cancelled = null): array
    {
        $data = json_decode($payload['messages'][1]['content'], true);
        $history = $data['conversation']['speaker_messages'] ?? [];
        $body = end($history) ?: '';
        usleep(300000);
        if (str_contains($body, 'falha do modelo')) {
            return ['error' => 'timeout'];
        }
        $output = isset($data['excerpts'])
            ? ['answer' => 'O procedimento publicado orienta a comparar DNS, TCP e HTTPS. Ainda são necessárias evidências do diagnóstico; nenhuma sondagem foi iniciada.', 'sources' => array_column($data['excerpts'], 'id')]
            : ['summary' => str_contains($body, 'piada') ? 'O cabo disse que precisava de espaço. Era só um problema de conexão! Um pouco de humor cabe no plantão tranquilo.' : 'Por aqui, tudo pronto para ajudar. Sou o assistente virtual do Agent Center; pode falar do seu jeito.', 'explanation' => 'Mensagem conversacional, sem ação operacional.', 'next_steps' => []];

        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($output)]]]]), 'request_id' => 'req_browser_fixture'];
    }
});
$app->handleRequest(Request::capture());
