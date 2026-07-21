<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EsimActivateRequest;
use App\Http\Requests\EsimRechargeRequest;
use App\Http\Requests\EsimSuspendRequest;
use App\Models\Esim;
use App\Models\UserEsim;
use App\Services\VodacomBalanceService;
use App\Services\VodacomRechargePayload;
use App\Services\VodacomSimActivationService;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EsimController extends Controller
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
        private readonly VodacomBalanceService $balances,
        private readonly VodacomSimActivationService $simActivation,
    ) {
    }

    public function organisationBalance()
    {
        return $this->proxy($this->vodacom->get('/api/organisations/balance'));
    }

    public function networks()
    {
        return $this->proxy($this->vodacom->get('/api/networks'));
    }

    public function products(Request $request)
    {
        $query = $request->only(['network_id', 'product_type', 'page', 'page_size']);
        return $this->proxy($this->vodacom->get('/api/products', $query));
    }

    public function sims(Request $request)
    {
        $query = $request->only(['iccid', 'imsi', 'msisdn', 'network_id', 'status', 'page', 'page_size']);
        return $this->proxy($this->vodacom->get('/api/sims', $query));
    }

    public function activate(EsimActivateRequest $request)
    {
        $data = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');

        $esim = null;
        if (! empty($data['msisdn'])) {
            $esim = Esim::findByMsisdn((string) $data['msisdn']);
        } elseif (! empty($data['iccid'])) {
            $esim = Esim::query()->where('iccid', strtoupper(trim((string) $data['iccid'])))->first();
        } elseif (! empty($data['imsi'])) {
            $esim = Esim::query()->where('imsi', trim((string) $data['imsi']))->first();
        }

        if ($esim) {
            try {
                $esim = $this->simActivation->activate($esim);

                return response()->json([
                    'success' => true,
                    'message' => 'SIM activated on Vodacom.',
                    'data' => $esim->only([
                        'id',
                        'msisdn',
                        'iccid',
                        'imsi',
                        'activation_status',
                        'vodacom_activated_at',
                        'provider_status',
                    ]),
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }

        return $this->proxy($this->vodacom->post('/api/sims-activate', $data));
    }

    public function suspend(EsimSuspendRequest $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');
        return $this->proxy($this->vodacom->post('/api/sims-suspend', $query));
    }

    public function usage(Request $request)
    {
        $query = $request->only([
            'iccid',
            'imsi',
            'msisdn',
            'status',
            'rule_id',
            'start_date',
            'end_date',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/usage', $query));
    }

    public function usageDetails(Request $request)
    {
        $query = $request->only([
            'msisdn',
            'iccid',
            'imsi',
            'start_date',
            'end_date',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/usage-details', $query));
    }

    public function recharges(Request $request)
    {
        $query = $request->only([
            'transaction_id',
            'rule_id',
            'rule_type',
            'sim_id',
            'msisdn',
            'start_date',
            'end_date',
            'start_datetime',
            'end_datetime',
            'page',
            'page_size',
        ]);

        return $this->proxy($this->vodacom->get('/api/recharge', $query));
    }

    public function recharge(EsimRechargeRequest $request)
    {
        $payload = VodacomRechargePayload::normalize(
            $request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id'])
        );

        return $this->proxy($this->vodacom->post('/api/recharge', [], $payload));
    }

    public function simsBalances(Request $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');

        Log::info('Vodacom sims-balances request', [
            'query' => $query,
            'ip' => $request->ip(),
        ]);

        $response = $this->vodacom->get('/api/sims-balances', $query);

        Log::info('Vodacom sims-balances response', [
            'query' => $query,
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 8000),
        ]);

        if ($response->status() === 202) {
            Log::info('Vodacom sims-balances queued for callback', [
                'query' => $query,
                'body' => mb_substr((string) $response->body(), 0, 2000),
            ]);
        } elseif ($response->successful()) {
            $synced = $this->balances->syncFromVodacomPayload($response->json());
            Log::info('Vodacom sims-balances synced to database', ['synced' => $synced]);
        } else {
            Log::warning('Vodacom sims-balances failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 2000),
            ]);
        }

        return $this->proxy($response);
    }

    public function rechargeCallback(Request $request): JsonResponse
    {
        Log::info('Vodacom recharge callback received', $this->callbackLogContext($request));

        $request->validate([
            'msisdn'    => 'required|string',
            'amount'    => 'required|numeric',
            'status'    => 'sometimes|string|max:30',
            'reference' => 'sometimes|string|max:100',
        ]);

        $esim = Esim::findByMsisdn($request->msisdn);

        if (! $esim) {
            Log::warning('Vodacom recharge callback: SIM not found', ['msisdn' => $request->msisdn]);

            return response()->json(['success' => false, 'message' => 'SIM not found'], 404);
        }

        $assignment = UserEsim::where('esim_id', $esim->id)->first();

        if (! $assignment) {
            Log::warning('Vodacom recharge callback: no user assignment', ['esim_id' => $esim->id, 'msisdn' => $esim->msisdn]);

            return response()->json(['success' => false, 'message' => 'No user assignment for this SIM'], 404);
        }

        $assignment->update([
            'last_recharge_amount'    => $request->amount,
            'last_recharge_reference' => $request->input('reference'),
            'last_recharge_status'    => $request->input('status', 'SUCCESS'),
            'last_recharged_at'       => now(),
        ]);

        Log::info('Vodacom recharge callback processed', ['user_esim_id' => $assignment->id]);

        return response()->json(['success' => true, 'message' => 'Recharge recorded']);
    }

    public function simsBalancesCallback(Request $request): JsonResponse
    {
        Log::info('Vodacom sims-balances callback received', $this->callbackLogContext($request));

        try {
            $validated = $request->validate([
                'msisdn'           => 'required|string',
                'balances'         => 'required_without:balance|array',
                'balances.AIRTIME' => 'nullable|numeric',
                'balances.DATA'    => 'nullable|numeric',
                'balances.SMS'     => 'nullable|numeric',
                'balance'          => 'required_without:balances|numeric',
                'currency'         => 'sometimes|string|max:10',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Vodacom sims-balances callback validation failed', [
                'errors' => $e->errors(),
                'body' => $request->all(),
            ]);

            throw $e;
        }

        $result = $this->balances->applyPayload($validated);

        if (! $result) {
            Log::warning('Vodacom sims-balances callback: SIM not in inventory', [
                'msisdn' => $validated['msisdn'],
            ]);

            return response()->json(['success' => false, 'message' => 'SIM not found in inventory'], 404);
        }

        Log::info('Vodacom sims-balances callback processed', $result);

        return response()->json([
            'success' => true,
            'message' => 'Balance updated',
            'esim_id' => $result['esim_id'],
            'assignment_updated' => $result['assignment_updated'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackLogContext(Request $request): array
    {
        return [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'query' => $request->query(),
            'body' => $request->all(),
            'raw_content' => mb_substr((string) $request->getContent(), 0, 8000),
        ];
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}
