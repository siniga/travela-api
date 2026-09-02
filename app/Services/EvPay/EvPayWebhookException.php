<?php

namespace App\Services\EvPay;

use RuntimeException;

class EvPayWebhookException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 403,
    ) {
        parent::__construct($message);
    }
}
