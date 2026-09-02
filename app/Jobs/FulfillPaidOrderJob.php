<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Esim\SimAssignmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FulfillPaidOrderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    /**
     * @param  array{payment_id?: string|null, transaction_reference?: string|null}  $evpayContext
     */
    public function __construct(
        public readonly int $orderId,
        public readonly array $evpayContext = [],
    ) {}

    public function handle(SimAssignmentService $simAssignment): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order || $order->payment_status !== 'paid') {
            return;
        }

        $simAssignment->fulfillPaidOrder($order, $this->evpayContext);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Paid order fulfillment exhausted retries', [
            'order_id' => $this->orderId,
            'error' => $e?->getMessage(),
        ]);
    }
}
