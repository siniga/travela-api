<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\OrderRechargeService;
use App\Services\PhysicalSimIssuanceService;
use App\Services\SimAssignmentService;
use App\Services\WalkInPhysicalSimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhysicalSimIssuanceController extends Controller
{
    public function __construct(
        private readonly PhysicalSimIssuanceService $issuance,
        private readonly SimAssignmentService $simAssignment,
        private readonly OrderRechargeService $orderRecharge,
        private readonly WalkInPhysicalSimService $walkInPhysicalSim,
    ) {
    }

    /**
     * Agent assigns a paid physical order to a specific ICCID from inventory.
     */
    public function assignPhysicalByOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draft_id' => ['required_without:order_id', 'nullable', 'string', 'max:100'],
            'order_id' => ['required_without:draft_id', 'nullable', 'integer', 'exists:orders,id'],
            'iccid' => ['required_without_all:esim_id,msisdn', 'nullable', 'string', 'max:50'],
            'esim_id' => ['required_without_all:iccid,msisdn', 'nullable', 'integer', 'exists:esims,id'],
            'msisdn' => ['required_without_all:iccid,esim_id', 'nullable', 'string', 'max:30'],
        ]);

        $order = $this->resolveOrder($data);
        $esim = $this->resolveEsim($data);

        $assignment = $this->simAssignment->assignPhysicalSimToPaidOrder($order, $esim);

        $recharge = $this->rechargeSafely($order->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Physical SIM assigned to customer for paid order.',
            'data' => [
                'user_esim' => $assignment->toAssignmentArray(),
                'sim_assignment' => [
                    'assigned' => true,
                    'sim_type' => Esim::SIM_TYPE_PHYSICAL,
                    'reason' => 'assigned_by_agent',
                ],
                'recharge' => $recharge,
            ],
        ], 201);
    }

    /**
     * Walk-in: assign an unassigned physical SIM to a customer without an order.
     */
    public function assignWalkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esim_id' => ['required', 'integer', 'exists:esims,id'],
            'iccid' => ['nullable', 'string', 'max:50'],
            'msisdn' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->walkInPhysicalSim->assign($data, $request->user());
        } catch (NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'SIM not found.',
            ], 404);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'SIM already assigned.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Physical SIM assigned. Customer will receive a login email.',
            'data' => [
                'user_id' => $result['user_id'],
                'esim_id' => $result['esim_id'],
                'msisdn' => $result['msisdn'],
                'iccid' => $result['iccid'],
                'email_sent' => $result['email_sent'],
            ],
        ], 201);
    }

    /**
     * Agent confirms a physical SIM card was handed to the customer.
     */
    public function issueByOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draft_id' => ['required_without:order_id', 'nullable', 'string', 'max:100'],
            'order_id' => ['required_without:draft_id', 'nullable', 'integer', 'exists:orders,id'],
            'user_esim_id' => ['nullable', 'integer', 'exists:user_esims,id'],
            'msisdn' => ['nullable', 'string', 'max:30'],
            'iccid' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->issuance->issueForOrder($data, $request->user());

        return response()->json([
            'success' => true,
            'message' => $result['already_issued']
                ? 'Physical SIM was already marked as issued.'
                : 'Physical SIM marked as issued to customer.',
            'already_issued' => $result['already_issued'],
            'data' => [
                'user_esim' => $result['assignment']->toAssignmentArray(),
                'physical_issuance' => $this->issuance->issuancePayload($result['assignment']),
            ],
        ], $result['already_issued'] ? 200 : 201);
    }

    /**
     * Admin confirms handover by assignment id.
     */
    public function issueByAssignment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $assignment = UserEsim::with(['esim', 'user', 'physicalIssuedBy'])->findOrFail($id);
        $result = $this->issuance->issueAssignment(
            $assignment,
            $request->user(),
            $data['location'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => $result['already_issued']
                ? 'Physical SIM was already marked as issued.'
                : 'Physical SIM marked as issued to customer.',
            'already_issued' => $result['already_issued'],
            'data' => [
                'user_esim' => $result['assignment']->toAssignmentArray(),
                'physical_issuance' => $this->issuance->issuancePayload($result['assignment']),
            ],
        ], $result['already_issued'] ? 200 : 201);
    }

    /**
     * @param  array{draft_id?: string, order_id?: int}  $data
     */
    private function resolveOrder(array $data): Order
    {
        $order = ! empty($data['order_id'])
            ? Order::find($data['order_id'])
            : Order::where('draft_id', trim((string) $data['draft_id']))->first();

        if (! $order) {
            abort(response()->json([
                'success' => false,
                'message' => 'Order not found.',
            ], 404));
        }

        return $order;
    }

    /**
     * @param  array{iccid?: string, esim_id?: int, msisdn?: string}  $data
     */
    private function resolveEsim(array $data): Esim
    {
        if (! empty($data['esim_id'])) {
            $esim = Esim::find($data['esim_id']);
        } elseif (! empty($data['iccid'])) {
            $esim = Esim::where('iccid', trim((string) $data['iccid']))->first();
        } else {
            $esim = Esim::findByMsisdn((string) $data['msisdn']);
        }

        if (! $esim) {
            abort(response()->json([
                'success' => false,
                'message' => 'SIM not found in inventory.',
            ], 404));
        }

        return $esim;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rechargeSafely(Order $order): ?array
    {
        try {
            return $this->orderRecharge->rechargePaidOrder($order);
        } catch (\Throwable $e) {
            Log::error('Order recharge failed after physical SIM assignment', [
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
}
