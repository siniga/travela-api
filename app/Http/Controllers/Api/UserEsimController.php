<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeEsimRechargeRequest;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\OrderRechargeService;
use App\Services\SimAssignmentService;
use App\Services\UserEsimOrderLinkService;
use App\Services\VodacomRechargePayload;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserEsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly OrderRechargeService $orderRecharge,
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly SimAssignmentService $simAssignment,
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
     * Activation payload (`qr_code_data`) for the user's assigned eSIM (from import).
     */
    public function activation(Request $request, UserEsim $userEsim): JsonResponse
    {
        if ((int) $userEsim->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this eSIM.',
            ], 403);
        }

        $userEsim->loadMissing('esim');
        $esim = $userEsim->esim;

        if (! $esim || $esim->sim_type !== Esim::SIM_TYPE_ESIM) {
            return response()->json([
                'success' => false,
                'message' => 'Activation data is not available for this eSIM.',
                'data' => [
                    'esim' => null,
                    'qr_code_data' => null,
                ],
            ], 404);
        }

        $qrCodeData = trim((string) ($esim->qr_code_data ?? ''));

        if ($qrCodeData === '') {
            return response()->json([
                'success' => false,
                'message' => 'Activation data is not available for this eSIM.',
                'data' => [
                    'esim' => $esim->toUserAssignmentApiArray(),
                    'qr_code_data' => null,
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'esim' => $esim->toUserAssignmentApiArray(),
                'qr_code_data' => $qrCodeData,
            ],
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

        $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);
        $latestPaid = $this->esimOrderLink->latestPaidOrderWithBundles($userId);

        if (! $latestPaid) {
            return response()->json([
                'success' => false,
                'status' => 'payment_required',
                'has_sim' => false,
                'poll_again' => false,
                'message' => 'Complete payment before a SIM can be assigned.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        if (! $this->simAssignment->orderIsPaid($latestPaid)) {
            return response()->json([
                'success' => false,
                'status' => 'payment_required',
                'has_sim' => false,
                'poll_again' => false,
                'message' => 'Complete payment before a SIM can be assigned.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        if ($latestPaid && $this->simAssignment->orderSimType($latestPaid) === Esim::SIM_TYPE_PHYSICAL) {
            return response()->json([
                'success' => false,
                'status' => 'waiting_for_agent',
                'has_sim' => false,
                'poll_again' => false,
                'message' => 'Physical SIM orders are assigned by an agent after payment.',
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        $available = $this->simAssignment->availableCount(Esim::SIM_TYPE_ESIM);

        return response()->json([
            'success' => false,
            'status' => 'waiting_for_inventory',
            'has_sim' => false,
            'poll_again' => true,
            'retry_after_seconds' => 300,
            'message' => $available > 0
                ? 'Payment complete. Call POST /me/esims/register to assign your eSIM.'
                : 'Payment complete but no eSIM numbers available yet. Keep polling.',
            'inventory' => [
                'available' => $available,
                'sim_type' => Esim::SIM_TYPE_ESIM,
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
            $result = $this->simAssignment->assignEsimForUserIfEligible($userId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'status' => 'not_eligible',
                'has_sim' => false,
                'poll_again' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Failed to assign SIM',
                'error' => $e->getMessage(),
            ], 500);
        }

        if (! ($result['assigned'] ?? false)) {
            $latestOrder = $this->esimOrderLink->latestOrderForUser($userId);

            return response()->json([
                'success' => false,
                'status' => ($result['reason'] ?? '') === 'no_esim_inventory' ? 'waiting_for_inventory' : 'not_assigned',
                'has_sim' => false,
                'poll_again' => ($result['reason'] ?? '') === 'no_esim_inventory',
                'retry_after_seconds' => 300,
                'message' => match ($result['reason'] ?? '') {
                    'no_esim_inventory' => 'No eSIM numbers available yet. Retry in a few minutes.',
                    'payment_not_paid' => 'Complete payment before a SIM can be assigned.',
                    default => 'SIM could not be assigned.',
                },
                'inventory' => [
                    'available' => $this->simAssignment->availableCount(Esim::SIM_TYPE_ESIM),
                    'sim_type' => Esim::SIM_TYPE_ESIM,
                ],
                'latest_order' => $latestOrder,
                'data' => null,
            ], 200);
        }

        $assignment = $result['assignment'];
        $created = ($result['reason'] ?? '') === 'assigned';

        return $this->registrationResponse(
            $assignment,
            $created,
            $created ? 201 : 200,
            $created ? 'assigned' : 'already_assigned',
            $result['recharge'] ?? null,
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
        ?array $recharge = null,
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
            'recharge' => $recharge ?? ($created ? $this->fulfillLatestPaidOrder($assignment->user_id) : null),
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

    /**
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

