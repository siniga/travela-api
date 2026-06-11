<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\SimAssignmentService;
use App\Services\UserEsimOrderLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentOrderLookupController extends Controller
{
    public function __construct(
        private readonly SimAssignmentService $simAssignment,
        private readonly UserEsimOrderLinkService $esimOrderLink,
    ) {
    }

    /**
     * Resolve a customer order from the SIM msisdn (or iccid) shown in /agent/esims/search.
     * Used at the counter to confirm payment before assigning a physical SIM.
     */
    public function byMsisdn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'msisdn' => ['required_without:iccid', 'nullable', 'string', 'max:30'],
            'iccid' => ['required_without:msisdn', 'nullable', 'string', 'max:50'],
        ]);

        $esim = $this->resolveEsim($data);
        if (! $esim) {
            return response()->json([
                'success' => false,
                'message' => 'SIM not found in inventory.',
            ], 404);
        }

        $assignment = UserEsim::query()
            ->with(['user', 'order.orderItems', 'esim'])
            ->where('esim_id', $esim->id)
            ->first();

        $order = null;
        $matchedBy = null;

        if ($assignment) {
            $assignment = $this->esimOrderLink->ensureAssignmentLinked($assignment);
            $assignment->loadMissing(['user', 'order.orderItems', 'esim']);
            $order = $assignment->order;
            $matchedBy = 'sim_assignment';
        }

        if (! $order && $esim->msisdn) {
            $order = $this->findOrderByPhoneDigits($this->normalizePhone($esim->msisdn));
            $matchedBy = $order ? 'order_metadata_or_payment_phone' : null;
        }

        if (! $order && ! empty($data['msisdn'])) {
            $order = $this->findOrderByPhoneDigits($this->normalizePhone($data['msisdn']));
            $matchedBy = $order ? 'customer_phone' : null;
        }

        $sim = $this->simPayload($esim, $assignment);
        $orderFound = $order !== null;

        if ($order) {
            $order->loadMissing(['user', 'orderItems']);
        }

        return response()->json([
            'success' => true,
            'order_found' => $orderFound,
            'matched_by' => $matchedBy,
            'message' => $orderFound
                ? 'Order found for this SIM.'
                : 'SIM found in inventory. No linked order yet — confirm payment via order number or assign after customer pays.',
            'sim' => $sim,
            'msisdn' => $sim['msisdn'],
            'iccid' => $sim['iccid'],
            'data' => $orderFound ? $this->formatAgentOrderPayload($order) : null,
        ]);
    }

    /**
     * @param  array{msisdn?: string, iccid?: string}  $data
     */
    private function resolveEsim(array $data): ?Esim
    {
        if (! empty($data['iccid'])) {
            return Esim::query()->where('iccid', trim((string) $data['iccid']))->first();
        }

        return Esim::findByMsisdn((string) $data['msisdn']);
    }

    private function findOrderByPhoneDigits(string $digits): ?Order
    {
        if ($digits === '' || strlen($digits) < 9) {
            return null;
        }

        $suffix = substr($digits, -9);

        return Order::query()
            ->with(['user', 'orderItems'])
            ->where(function ($q) use ($digits, $suffix) {
                $q->where('metadata->msisdn', $digits)
                    ->orWhere('metadata->msisdn', 'like', '%'.$suffix)
                    ->orWhere('payment_payload->phoneNumber', $digits)
                    ->orWhere('payment_payload->phoneNumber', 'like', '%'.$suffix);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAgentOrderPayload(Order $order): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $simType = $meta['simType'] ?? null;
        $isPaid = $this->simAssignment->orderIsPaid($order);

        $primaryItem = $order->orderItems->first();

        return [
            'order_id' => $order->id,
            'order_number' => $order->draft_id,
            'draft_id' => $order->draft_id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'is_paid' => $isPaid,
            'sim_type' => $simType,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'paid_at' => $order->paid_at,
            'user' => $order->user?->only(['id', 'name', 'email', 'role']),
            'bundle' => $primaryItem ? [
                'bundle_id' => $primaryItem->bundle_id,
                'bundle_name' => $primaryItem->bundle_name,
                'data_amount' => $primaryItem->data_amount,
                'validity_days' => $primaryItem->validity_days,
                'price' => $primaryItem->price,
                'currency' => $primaryItem->currency,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simPayload(Esim $esim, ?UserEsim $assignment): array
    {
        return [
            'id' => $esim->id,
            'iccid' => $esim->iccid,
            'msisdn' => $esim->msisdn,
            'sim_type' => $esim->sim_type,
            'is_assigned' => $assignment !== null,
            'user_esim_id' => $assignment?->id,
        ];
    }
}
