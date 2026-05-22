<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeEsimRechargeRequest;
use App\Models\Esim;
use App\Models\Order;
use App\Models\UserEsim;
use App\Services\OrderRechargeService;
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
    ) {
    }

    public function index(Request $request)
    {
        $esims = $request->user()
            ->esims()
            ->with('esim')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $esims,
        ]);
    }

    /**
     * Assign the authenticated user a SIM from inventory if they do not already have one.
     */
    public function register(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $existing = UserEsim::query()
            ->where('user_id', $userId)
            ->whereHas('esim', fn ($q) => $q->whereNotNull('msisdn')->where('msisdn', '!=', ''))
            ->with('esim')
            ->first();

        if ($existing) {
            return $this->registrationResponse($existing, false, 200);
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

                $esim = Esim::query()
                    ->whereNotNull('msisdn')
                    ->where('msisdn', '!=', '')
                    ->whereNotIn('id', UserEsim::query()->select('esim_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $esim || UserEsim::where('esim_id', $esim->id)->exists()) {
                    return ['assignment' => null, 'created' => false];
                }

                $assignment = UserEsim::create([
                    'user_id' => $userId,
                    'esim_id' => $esim->id,
                ]);

                $esim->update(['status' => 'MANAGED']);

                return ['assignment' => $assignment->load('esim'), 'created' => true];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign SIM',
                'error' => $e->getMessage(),
            ], 500);
        }

        if (! $result['assignment']) {
            return response()->json([
                'success' => false,
                'message' => 'No unassigned SIMs available in inventory',
            ], 422);
        }

        return $this->registrationResponse(
            $result['assignment'],
            $result['created'],
            $result['created'] ? 201 : 200,
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

    private function registrationResponse(UserEsim $assignment, bool $created, int $status): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $created ? 'SIM assigned successfully' : 'SIM already assigned',
            'data' => $assignment,
            'recharge' => $this->fulfillLatestPaidOrder($assignment->user_id),
        ], $status);
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
            ->where('payment_status', 'paid')
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

