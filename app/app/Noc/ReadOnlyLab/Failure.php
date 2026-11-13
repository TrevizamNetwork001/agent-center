<?php

namespace App\Noc\ReadOnlyLab;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Closed error codes only; never include SSH errors, raw output, keys or passwords.
        parent::__construct('SIMULADO / LAB: '.$reason);
    }
}
