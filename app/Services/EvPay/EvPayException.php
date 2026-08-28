<?php

namespace App\Services\EvPay;

use RuntimeException;

class EvPayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
        public readonly string $errorCode = 'evpay_error',
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
