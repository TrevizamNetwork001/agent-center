<?php

namespace App\Conversation;

// Channel-independent input. Identity must be verified by the adapter first.
// Transport IDs must map to a stable requestKey scoped to that verified user.
final readonly class IncomingMessage
{
    public function __construct(public Identity $identity, public string $requestKey, public string $body) {}
}
