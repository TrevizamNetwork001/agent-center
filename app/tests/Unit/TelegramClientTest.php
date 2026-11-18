<?php

namespace Tests\Unit;

use App\Telegram\Bot;
use App\Telegram\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramClientTest extends TestCase
{
    private function bot(): Bot
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $bot = new Bot;
        $bot->forceFill(['token_cipher' => Crypt::encryptString('123456:'.str_repeat('a', 35))]);

        return $bot;
    }

    public function test_transport_uses_ipv4_without_disabling_tls_or_following_redirects(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) {
            $this->assertSame('v4', $options['force_ip_resolve']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 42]]);
        });
        $this->assertSame('OK', app(Client::class)->call($this->bot(), 'sendMessage', ['chat_id' => 1, 'text' => 'Fixture'])['status']);
        Http::assertSentCount(1);
    }

    public static function failures(): array
    {
        $zero = ['http_code' => 0, 'request_size' => 0, 'size_upload' => 0.0, 'connect_time' => 0.0, 'pretransfer_time' => 0.0];

        return [[6, 'TRANSPORT_UNAVAILABLE'], [7, 'TRANSPORT_UNAVAILABLE'], [28, 'DELIVERY_UNKNOWN'], [35, 'DELIVERY_UNKNOWN'], [null, 'DELIVERY_UNKNOWN'],
            [28, 'TRANSPORT_UNAVAILABLE', $zero],
            [28, 'DELIVERY_UNKNOWN', [...$zero, 'request_size' => 100]],
            [28, 'DELIVERY_UNKNOWN', [...$zero, 'size_upload' => 2]],
            [28, 'DELIVERY_UNKNOWN', [...$zero, 'connect_time' => 0.02]],
            [28, 'DELIVERY_UNKNOWN', array_diff_key($zero, ['request_size' => true])]];
    }

    #[DataProvider('failures')]
    public function test_only_proven_connection_failures_are_retryable(?int $errno, string $status, array $stats = []): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($errno, $stats) {
            ($options['on_stats'])(new TransferStats(new Request('POST', 'https://api.telegram.org/'), handlerErrorData: $errno, handlerStats: $stats));
            throw new ConnectionException('Private transport detail');
        });
        $this->assertSame(['status' => $status], app(Client::class)->call($this->bot(), 'sendMessage'));
    }
}
