<?php

namespace App\Conversation;

enum State: string
{
    case New = 'NEW';
    case Active = 'ACTIVE';
    case Target = 'WAITING_FOR_TARGET';
    case Confirmation = 'WAITING_FOR_CONFIRMATION';
    case Running = 'DIAGNOSTIC_RUNNING';
    case Completed = 'DIAGNOSTIC_COMPLETED';
    case FollowUp = 'FOLLOW_UP';
    case Cancelled = 'CANCELLED';
    case Closed = 'CLOSED';

    public function permits(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next->value, match ($this) {
            self::New => ['ACTIVE', 'CLOSED'],self::Active => ['WAITING_FOR_TARGET', 'WAITING_FOR_CONFIRMATION', 'DIAGNOSTIC_RUNNING', 'CANCELLED', 'CLOSED'],
            self::Target => ['DIAGNOSTIC_RUNNING', 'WAITING_FOR_CONFIRMATION', 'CANCELLED', 'CLOSED'],self::Confirmation => ['DIAGNOSTIC_RUNNING', 'FOLLOW_UP', 'WAITING_FOR_TARGET', 'CANCELLED', 'CLOSED'],
            self::Running => ['DIAGNOSTIC_COMPLETED', 'CANCELLED'],self::Completed => ['FOLLOW_UP', 'WAITING_FOR_CONFIRMATION', 'CLOSED', 'CANCELLED'],
            self::FollowUp => ['WAITING_FOR_CONFIRMATION', 'WAITING_FOR_TARGET', 'DIAGNOSTIC_RUNNING', 'CLOSED', 'CANCELLED'],self::Cancelled => ['CLOSED'],self::Closed => []
        }, true);
    }
}
