<?php

namespace App\Jobs;

use App\Services\PaymentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEvPayWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $deliveryId,
    ) {
    }

    public function handle(PaymentProcessingService $payments): void
    {
        $payments->processWebhookDelivery($this->deliveryId);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('EVPay webhook job failed', [
            'delivery_id' => $this->deliveryId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
