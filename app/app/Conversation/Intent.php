<?php

namespace App\Conversation;

enum Intent: string
{
    case Greeting = 'GREETING';
    case Social = 'SOCIAL';
    case Topic = 'CHANGE_TOPIC';
    case Knowledge = 'KNOWLEDGE_QUESTION';
    case Path = 'REQUEST_NETWORK_PATH';
    case Start = 'START_DIAGNOSTIC';
    case Continue = 'CONTINUE_DIAGNOSTIC';
    case Target = 'PROVIDE_TARGET';
    case Detail = 'REQUEST_MORE_DETAIL';
    case Another = 'REQUEST_ANOTHER_TEST';
    case Confirm = 'CONFIRM';
    case Deny = 'DENY';
    case Cancel = 'CANCEL';
    case Close = 'CLOSE';
    case Acknowledge = 'ACKNOWLEDGE';
    case Help = 'HELP';
    case Unknown = 'UNKNOWN';
}
