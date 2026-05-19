<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Log;

class OrderRechargeService
{
    private const FULFILLED_STATUSES = ['sent', 'success'];

    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    /**
     * Recharge bundle lines on a paid order via Vodacom SIM Manager.
     *
     * @return array{processed:int, skipped:int, failed:int, errors:array<int, string>}
     */
    public function fulfillOrder(Order $order): array
    {
        $order->refresh();
        $order->loadMissing(['orderItems.bundle']);

        if (! $this->orderIsPaid($order)) {
            return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        }

        $assignment = $this->resolveUserEsim($order->user_id);
        if (! $assignment?->esim) {
            $message = 'No eSIM assignment with MSISDN found for user.';
            Log::warning('Order recharge skipped: missing user eSIM', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
            ]);
            $this->recordFulfillmentError($order, $message);

            return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [$message]];
        }

        $esim = $assignment->esim;
        if (! $esim->msisdn || $esim->network_id === null) {
            $message = 'Assigned eSIM is missing msisdn or network_id.';
            Log::warning('Order recharge skipped: incomplete eSIM', [
                'order_id' => $order->id,
                'esim_id' => $esim->id,
            ]);
            $this->recordFulfillmentError($order, $message);

            return ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [$message]];
        }

        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($this->rechargeableItems($order) as $item) {
            if ($this->itemAlreadyFulfilled($item)) {
                $result['skipped']++;

                continue;
            }

            $bundle = $item->bundle;
            if (! $bundle?->sim_bundle_id) {
                $message = "Order item {$item->id} has no Vodacom product (sim_bundle_id).";
                Log::warning('Order recharge skipped: missing sim_bundle_id', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'bundle_id' => $item->bundle_id,
                ]);
                $result['skipped']++;
                $result['errors'][] = $message;

                continue;
            }

            $reference = $this->generateRechargeReference($order, $item);
            $payload = array_filter([
                'msisdn' => $esim->msisdn,
                'network_id' => (int) $esim->network_id,
                'product_id' => (int) $bundle->sim_bundle_id,
                'reference' => $reference,
                'airtime_amount' => (string) $item->price,
            ], fn ($v) => $v !== null && $v !== '');

            try {
                $response = $this->vodacom->post('/api/recharge', [], $payload);
                $body = $response->json();
                $status = $response->successful() ? 'sent' : 'failed';

                $this->persistItemRecharge($item, [
                    'reference' => $reference,
                    'status' => $status,
                    'requested_at' => now()->toIso8601String(),
                    'http_status' => $response->status(),
                    'payload' => $payload,
                    'response' => is_array($body) ? $body : ['raw' => $response->body()],
                ]);

                if ($response->successful()) {
                    $result['processed']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = "Vodacom recharge failed for item {$item->id} (HTTP {$response->status()}).";
                }
            } catch (\Throwable $e) {
                Log::error('Order recharge request failed', [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                $this->persistItemRecharge($item, [
                    'reference' => $reference,
                    'status' => 'failed',
                    'requested_at' => now()->toIso8601String(),
                    'payload' => $payload,
                    'error' => $e->getMessage(),
                ]);

                $result['failed']++;
                $result['errors'][] = $e->getMessage();
            }
        }

        $this->recordFulfillmentSummary($order, $result);

        return $result;
    }

    private function orderIsPaid(Order $order): bool
    {
        return $order->payment_status === 'paid' || $order->status === 'paid';
    }

    /**
     * First user_esims row (by id) with a non-null MSISDN on the linked esim.
     */
    private function resolveUserEsim(int $userId): ?UserEsim
    {
        return UserEsim::query()
            ->where('user_id', $userId)
            ->with('esim')
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function rechargeableItems(Order $order)
    {
        return $order->orderItems
            ->filter(fn (OrderItem $item) => $item->type === 'bundle')
            ->filter(fn (OrderItem $item) => $item->bundle_id !== null)
            ->values();
    }

    private function itemAlreadyFulfilled(OrderItem $item): bool
    {
        $recharge = $this->itemMetadataArray($item)['recharge'] ?? [];

        if (! is_array($recharge)) {
            return false;
        }

        $status = $recharge['status'] ?? null;
        $reference = $recharge['reference'] ?? null;

        return $reference && in_array($status, self::FULFILLED_STATUSES, true);
    }

    private function generateRechargeReference(Order $order, OrderItem $item): string
    {
        $existing = $this->itemMetadataArray($item)['recharge']['reference'] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return sprintf('RCH-%s-%d-%d', now()->format('Ymd'), $order->id, $item->id);
    }

    /**
     * @param  array<string, mixed>  $recharge
     */
    private function persistItemRecharge(OrderItem $item, array $recharge): void
    {
        $meta = $this->itemMetadataArray($item);
        $meta['recharge'] = $recharge;
        $item->metadata = $meta;
        $item->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemMetadataArray(OrderItem $item): array
    {
        $raw = $item->getAttributes()['metadata'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $item->metadata;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array{processed:int, skipped:int, failed:int, errors:array<int, string>}  $result
     */
    private function recordFulfillmentSummary(Order $order, array $result): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['fulfillment'] = [
            'last_run_at' => now()->toIso8601String(),
            'processed' => $result['processed'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ];
        $order->metadata = $meta;
        $order->save();
    }

    private function recordFulfillmentError(Order $order, string $message): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['fulfillment'] = [
            'last_run_at' => now()->toIso8601String(),
            'error' => $message,
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $order->metadata = $meta;
        $order->save();
    }
}
