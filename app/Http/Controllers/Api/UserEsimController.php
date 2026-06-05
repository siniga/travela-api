<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeEsimRechargeRequest;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\OrderRechargeService;
use App\Services\SimInventoryService;
use App\Services\UserEsimOrderLinkService;
use App\Services\VodacomRechargePayload;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserEsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly OrderRechargeService $orderRecharge,
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly SimInventoryService $inventory,
    ) {
    }

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        $esims = $request->user()
            ->esims()
            ->with(['esim', 'bundle', 'order', 'orderItem'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (UserEsim $row) {
                $row = $this->esimOrderLink->ensureAssignmentLinked($row);
                $row->loadMissing(['esim', 'bundle', 'order', 'orderItem']);

                return $row->toAssignmentArray();
            });

        return response()->json([
            'success' => true,
            'data' => $esims,
            'latest_order' => $this->esimOrderLink->latestOrderForUser($userId),
        ]);
    }

    /**
     * Poll-friendly status: has the user been assigned a SIM yet? Is inventory available?
     * Frontend can call this every ~5 minutes (no assignment side effects).
     */
    public function assignmentStatus(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $existing = $this->findUserAssignment($userId);

        if ($existing) {
            $existing = $this->esimOrderLink->ensureAssignmentLinked($existing);
            $existing->loadMissing(['esim', 'bundle', 'order', 'orderItem']);

            return $this->assignmentStatusResponse($existing, 'assigned');
        }

        $available = $this->availableInventoryCount();
        $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);

        return response()->json([
            'success' => false,
            'status' => 'waiting_for_inventory',
            'has_sim' => false,
            'poll_again' => true,
            'retry_after_seconds' => 300,
            'message' => $available > 0
                ? 'A number is available. Call POST /me/esims/register to assign it.'
                : 'No numbers available yet. Keep polling until inventory is added.',
            'inventory' => [
                'available' => $available,
            ],
            'latest_order' => $latestOrder,
            'data' => null,
        ], 200);
    }

    /**
     * Self-assign: pick the next free active number from inventory for this user only.
     * Safe to poll via POST when GET status shows inventory (or retry every 5 minutes).
     */
    public function register(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $existing = $this->findUserAssignment($userId);

        if ($existing) {
            $existing = $this->esimOrderLink->ensureAssignmentLinked($existing);
            $existing->loadMissing(['esim', 'bundle', 'order', 'orderItem']);

            return $this->registrationResponse($existing, false, 200, 'already_assigned');
        }

        try {
            $result = DB::transaction(function () use ($userId) {
                $existing = UserEsim::query()
                    ->where('user_id', $userId)
                    ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
                    ->with('esim')
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['assignment' => $existing, 'created' => false];
                }

                $esim = $this->nextAvailableEsimQuery()->lockForUpdate()->first();

                if (! $esim || UserEsim::where('esim_id', $esim->id)->exists()) {
                    return ['assignment' => null, 'created' => false];
                }

                $assignment = UserEsim::create([
                    'user_id' => $userId,
                    'esim_id' => $esim->id,
                ]);

                $esim->update(['status' => 'MANAGED']);

                $assignment = $this->esimOrderLink->linkAssignmentFromLatestPaidOrder($assignment);

                $assignment->load(['esim', 'bundle', 'order', 'orderItem']);

                return ['assignment' => $assignment, 'created' => true];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign SIM',
                'error' => $e->getMessage(),
            ], 500);
        }

        if (! $result['assignment']) {
            $available = $this->availableInventoryCount();
            $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);

            return response()->json([
                'success' => false,
                'status' => 'waiting_for_inventory',
                'has_sim' => false,
                'poll_again' => true,
                'retry_after_seconds' => 300,
                'message' => 'No unassigned numbers in inventory yet. Retry in a few minutes.',
                'inventory' => [
                    'available' => $available,
                ],
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        return $this->registrationResponse(
            $result['assignment'],
            $result['created'],
            $result['created'] ? 201 : 200,
            $result['created'] ? 'assigned' : 'already_assigned',
        );
    }

    public function recharges(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');
        return $this->proxy($this->vodacom->get('/api/recharge', $query));
    }

    public function usage(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');
        return $this->proxy($this->vodacom->get('/api/usage', $query));
    }

    public function usageDetails(Request $request)
    {
        $data = $request->validate([
            'msisdn' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        $query = array_filter($request->only(['msisdn', 'start_date', 'end_date', 'page', 'page_size']), fn ($v) => $v !== null && $v !== '');
        return $this->proxy($this->vodacom->get('/api/usage-details', $query));
    }

    public function recharge(MeEsimRechargeRequest $request)
    {
        $data = $request->validated();

        $esim = $this->requireOwnedEsim($request, $data['msisdn']);

        if (! is_null($esim->esim?->network_id) && (int) $esim->esim->network_id !== (int) $data['network_id']) {
            return response()->json(['message' => 'You do not have access to this eSIM.'], 403);
        }

        return $this->proxy($this->postVodacomRecharge(
            $request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id'])
        ));
    }

    private function registrationResponse(
        UserEsim $assignment,
        bool $created,
        int $status,
        string $assignmentStatus = 'assigned',
    ): JsonResponse {
        $assignment->loadMissing(['esim', 'bundle', 'order', 'orderItem']);

        return response()->json([
            'success' => true,
            'status' => $assignmentStatus,
            'has_sim' => true,
            'poll_again' => false,
            'message' => $created ? 'SIM assigned successfully' : 'SIM already assigned',
            'data' => $assignment->toAssignmentArray(),
            'latest_order' => $this->esimOrderLink->latestOrderForUser((int) $assignment->user_id),
            'recharge' => $created ? $this->fulfillLatestPaidOrder($assignment->user_id) : null,
        ], $status);
    }

    private function assignmentStatusResponse(UserEsim $assignment, string $status): JsonResponse
    {
        $assignment->loadMissing(['esim', 'bundle', 'order', 'orderItem']);

        return response()->json([
            'success' => true,
            'status' => $status,
            'has_sim' => true,
            'poll_again' => false,
            'message' => 'SIM already assigned',
            'data' => $assignment->toAssignmentArray(),
            'latest_order' => $this->esimOrderLink->latestOrderForUser((int) $assignment->user_id),
        ], 200);
    }

    private function findUserAssignment(int $userId): ?UserEsim
    {
        return UserEsim::query()
            ->where('user_id', $userId)
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->with(['esim', 'bundle', 'order', 'orderItem'])
            ->first();
    }

    private function nextAvailableEsimQuery()
    {
        return Esim::query()
            ->whereNotNull('msisdn')
            ->where('msisdn', '!=', '')
            ->where('provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->whereNotIn('id', UserEsim::query()->select('esim_id'))
            ->orderBy('id');
    }

    private function availableInventoryCount(): int
    {
        $networkId = (int) config('travela.inventory.default_network_id', 1);
        $stock = $this->inventory->stockLevels($networkId);

        return (int) $stock['available'];
    }

    /**
     * Fulfill the user's latest paid order (payment already completed) via Vodacom recharge per bundle item.
     *
     * @return array<string, mixed>|null
     */
    private function fulfillLatestPaidOrder(int $userId): ?array
    {
        $order = Order::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('payment_status', 'paid')
                    ->orWhere('status', 'paid');
            })
            ->where(function ($q) {
                $q->whereNull('recharge_status')
                    ->orWhereIn('recharge_status', ['pending_esim', 'pending_retry', 'in_progress', 'failed']);
            })
            ->orderByDesc('id')
            ->first();

        if (! $order) {
            return null;
        }

        try {
            return $this->orderRecharge->rechargePaidOrder($order);
        } catch (\Throwable $e) {
            Log::error('Order recharge failed after SIM registration', [
                'user_id' => $userId,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
                'recharge_status' => 'failed',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    private function postVodacomRecharge(array $payload)
    {
        return $this->vodacom->post('/api/recharge', [], VodacomRechargePayload::normalize($payload));
    }

    private function requireOwnedEsim(Request $request, string $msisdn): UserEsim
    {
        $esim = $request->user()
            ->esims()
            ->whereHas('esim', fn ($q) => $q->where('msisdn', $msisdn))
            ->with('esim')
            ->first();

        if (! $esim) {
            abort(response()->json(['message' => 'You do not have access to this eSIM.'], 403));
        }

        return $esim;
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}

