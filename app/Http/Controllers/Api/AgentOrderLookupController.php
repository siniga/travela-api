<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UserEsim;
use App\Services\SimAssignmentService;
use App\Services\UserEsimOrderLinkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AgentOrderLookupController extends Controller
{
    /** Order numbers are matched from the trailing digits the agent types at the counter. */
    private const MIN_ORDER_SUFFIX_LENGTH = 3;

    public function __construct(
        private readonly SimAssignmentService $simAssignment,
        private readonly UserEsimOrderLinkService $esimOrderLink,
    ) {
    }

    /**
     * Counter lookup: match paid physical orders by the last digits of draft_id.
     *
     * Query: order_suffix, draft_id, order_number, or q. Optional physical_only, paid_only,
     * unassigned_only (default true), limit.
     */
    public function searchByOrderSuffix(Request $request): JsonResponse
    {
        $raw = trim((string) (
            $request->query('order_suffix')
            ?? $request->query('draft_id')
            ?? $request->query('order_number')
            ?? $request->query('q')
            ?? ''
        ));

        if ($raw === '') {
            return response()->json([
                'success' => false,
                'message' => 'Provide order_suffix, draft_id, order_number, or q (last digits of the order number).',
                'min_length' => self::MIN_ORDER_SUFFIX_LENGTH,
                'suggestions' => [],
            ], 422);
        }

        if ($this->looksLikeFullOrderNumber($raw)) {
            $order = $this->baseOrderQuery($request)->where('draft_id', $raw)->first();
            if ($order) {
                return response()->json([
                    'success' => true,
                    'query' => $raw,
                    'match_mode' => 'exact',
                    'count' => 1,
                    'suggestions' => [$this->formatOrderSuggestion($order, strlen($raw))],
                ]);
            }
        }

        $suffix = $this->normalizeOrderSuffix($raw);
        $suffixDigits = preg_replace('/\D+/', '', $suffix) ?? '';

        if ($suffixDigits !== '' && strlen($suffixDigits) < self::MIN_ORDER_SUFFIX_LENGTH
            && strlen($suffix) < self::MIN_ORDER_SUFFIX_LENGTH) {
            return response()->json([
                'success' => true,
                'query' => $suffix,
                'min_length' => self::MIN_ORDER_SUFFIX_LENGTH,
                'match_mode' => 'draft_id_suffix',
                'message' => 'Type at least '.self::MIN_ORDER_SUFFIX_LENGTH.' characters from the end of the order number.',
                'suggestions' => [],
            ]);
        }

        $limit = min(max((int) $request->query('limit', 10), 1), 20);
        $orders = $this->fetchSuffixOrderCandidates($request, $suffix, $suffixDigits, $limit);

        $suffixLen = max(strlen($suffix), strlen($suffixDigits));

        return response()->json([
            'success' => true,
            'query' => $suffix,
            'min_length' => self::MIN_ORDER_SUFFIX_LENGTH,
            'match_mode' => 'draft_id_suffix',
            'count' => $orders->count(),
            'suggestions' => $orders
                ->map(fn (Order $order) => $this->formatOrderSuggestion($order, $suffixLen))
                ->values(),
        ]);
    }

    /**
     * List paid physical-SIM orders that do not yet have a SIM/number assigned.
     *
     * Query: paid_only (default true), limit (default 50, max 100), page (default 1).
     */
    public function unassignedPhysicalOrders(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        $orders = $this->fetchUnassignedPhysicalOrders($request);

        $total = $orders->count();
        $pageOrders = $orders->forPage($page, $limit)->values();

        return response()->json([
            'success' => true,
            'count' => $pageOrders->count(),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'orders' => $pageOrders
                ->map(fn (Order $order) => $this->formatOrderSuggestion($order, self::MIN_ORDER_SUFFIX_LENGTH))
                ->values(),
        ]);
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
        $order->loadMissing(['user', 'kyc']);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $simType = $meta['simType'] ?? null;
        $isPaid = $this->simAssignment->orderIsPaid($order);
        $customer = $this->customerDetailsForOrder($order);

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
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'],
            'user' => $customer['user'],
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

    private function looksLikeFullOrderNumber(string $value): bool
    {
        return str_starts_with(strtoupper($value), 'DRAFT-') || strlen($value) > 12;
    }

    private function normalizeOrderSuffix(string $value): string
    {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private function baseOrderQuery(Request $request): Builder
    {
        $query = Order::query()
            ->with(['user', 'orderItems', 'trip', 'kyc']);

        if ($request->boolean('physical_only', true)) {
            $query->where('metadata->simType', Esim::SIM_TYPE_PHYSICAL);
        }

        if ($request->boolean('paid_only', true)) {
            $query->where(function (Builder $q) {
                $q->where('payment_status', 'paid')
                    ->orWhere('status', 'paid');
            });
        }

        return $query;
    }

    /**
     * @return Collection<int, Order>
     */
    private function fetchUnassignedPhysicalOrders(Request $request): Collection
    {
        $assignedOrderIds = UserEsim::query()
            ->whereNotNull('order_id')
            ->pluck('order_id');

        $query = $this->baseOrderQuery($request)
            ->where('metadata->simType', Esim::SIM_TYPE_PHYSICAL);

        if ($assignedOrderIds->isNotEmpty()) {
            $query->whereNotIn('id', $assignedOrderIds);
        }

        return $query
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Order $order) => $this->simAssignment->findAssignmentForOrder($order) === null)
            ->values();
    }

    /**
     * @return Collection<int, Order>
     */
    private function fetchSuffixOrderCandidates(
        Request $request,
        string $suffix,
        string $suffixDigits,
        int $limit,
    ): Collection {
        $escaped = $this->escapeLike($suffix);
        $query = $this->baseOrderQuery($request)
            ->where(function (Builder $q) use ($escaped, $suffixDigits) {
                $q->where('draft_id', 'like', '%'.$escaped);

                if ($suffixDigits !== '' && $suffixDigits !== $escaped) {
                    $q->orWhere('draft_id', 'like', '%'.$this->escapeLike($suffixDigits));
                }
            })
            ->orderByDesc('id')
            ->limit($limit * 5);

        $orders = $query->get()->filter(
            fn (Order $order) => $this->orderMatchesSuffix($order, $suffix, $suffixDigits)
        );

        if ($request->boolean('unassigned_only', true)) {
            $orders = $orders->filter(
                fn (Order $order) => $this->simAssignment->findAssignmentForOrder($order) === null
            );
        }

        return $orders->take($limit)->values();
    }

    private function orderMatchesSuffix(Order $order, string $suffix, string $suffixDigits): bool
    {
        $draftId = (string) $order->draft_id;

        if ($suffix !== '' && str_ends_with($draftId, $suffix)) {
            return true;
        }

        if ($suffixDigits === '') {
            return false;
        }

        $orderDigits = preg_replace('/\D+/', '', $draftId) ?? '';

        return $orderDigits !== '' && str_ends_with($orderDigits, $suffixDigits);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrderSuggestion(Order $order, int $suffixLen): array
    {
        $order->loadMissing(['user', 'orderItems', 'trip', 'kyc']);
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $isPaid = $this->simAssignment->orderIsPaid($order);
        $assignment = $this->simAssignment->findAssignmentForOrder($order);
        $customer = $this->customerDetailsForOrder($order);
        $primaryItem = $order->orderItems->first();
        $draftId = (string) $order->draft_id;
        $displaySuffix = substr($draftId, -max($suffixLen, self::MIN_ORDER_SUFFIX_LENGTH));

        return [
            'order_id' => $order->id,
            'draft_id' => $order->draft_id,
            'order_number' => $order->draft_id,
            'draft_id_suffix' => $displaySuffix,
            'label' => $customer['name']
                ? $customer['name'].' · …'.$displaySuffix
                : '…'.$displaySuffix,
            'value' => $order->draft_id,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'is_paid' => $isPaid,
            'sim_type' => $meta['simType'] ?? null,
            'has_sim_assignment' => $assignment !== null,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'paid_at' => $order->paid_at,
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'],
            'user' => $customer['user'],
            'bundle' => $primaryItem ? $this->bundlePayload($primaryItem) : null,
            'order_items' => $order->orderItems
                ->map(fn (OrderItem $item) => $this->bundlePayload($item))
                ->values()
                ->all(),
            'trip' => $order->trip?->only([
                'destination_country',
                'arrival_date',
                'departure_date',
                'duration_days',
            ]),
            'kyc' => $order->kyc ? $order->kyc->only([
                'passport_id',
                'passport_country',
                'nationality',
                'gender',
                'reason',
                'arrival_date',
                'departure_date',
            ]) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bundlePayload(OrderItem $item): array
    {
        return $item->only([
            'id',
            'bundle_id',
            'bundle_name',
            'data_amount',
            'validity_days',
            'price',
            'currency',
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * @return array{name: ?string, email: ?string, user: ?array{id: int, name: string, email: string, role: string}}
     */
    private function customerDetailsForOrder(Order $order): array
    {
        $user = $order->user;

        if (! $user && $order->user_id) {
            $order->loadMissing('user');
            $user = $order->user;
        }

        if (! $user && $order->relationLoaded('kyc') && $order->kyc?->user_id) {
            $order->kyc->loadMissing('user');
            $user = $order->kyc->user;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];

        $name = $user?->name
            ?? (is_string($meta['customer_name'] ?? null) ? $meta['customer_name'] : null);
        $email = $user?->email
            ?? (is_string($meta['customer_email'] ?? null) ? $meta['customer_email'] : null);

        return [
            'name' => $name,
            'email' => $email,
            'user' => $user?->only(['id', 'name', 'email', 'role']),
        ];
    }
}
