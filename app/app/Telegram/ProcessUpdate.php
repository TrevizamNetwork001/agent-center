<?php

namespace App\Telegram;

use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProcessUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $tenant, public string $updateId) {}

    public function handle(Runner $runner): void
    {
        try {
            $runner->process($this->tenant, $this->updateId);
        } catch (\Throwable $e) {
            app(TenantContext::class)->apply($this->tenant);
            try {
                $row = Update::find($this->updateId);
                if ($row && $row->status === 'PENDING') {
                    $denied = $e instanceof HttpExceptionInterface || $e instanceof ValidationException || $e instanceof AuthorizationException;
                    $attempts = $row->attempts + 1;
                    $row->forceFill(['status' => $denied ? 'DENIED' : ($attempts >= 5 ? 'FAILED' : 'PENDING'), 'attempts' => $attempts, 'last_error' => $denied ? 'AUTHORIZATION_OR_INPUT_DENIED' : 'PROCESSING_FAILED', 'next_attempt_at' => now()->addSeconds(min(300, 10 * 2 ** $attempts)), 'payload_cipher' => $denied || $attempts >= 5 ? null : $row->payload_cipher])->save();
                    app(Accounts::class)->audit('telegram.processing_failed');
                }
            } finally {
                app(TenantContext::class)->apply();
            }
        }
    }
}
