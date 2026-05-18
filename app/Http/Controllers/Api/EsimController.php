<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EsimActivateRequest;
use App\Http\Requests\EsimRechargeRequest;
use App\Http\Requests\EsimSuspendRequest;
use App\Models\Esim;
use App\Models\UserEsim;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EsimController extends Controller
{
    public function __construct(private readonly VodacomSimManagerService $vodacom)
    {
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
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');
        return $this->proxy($this->vodacom->post('/api/sims-activate', $query));
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
        $payload = $request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id']);
        $payload = array_filter($payload, fn ($v) => ! is_null($v) && $v !== '');

        return $this->proxy($this->vodacom->post('/api/recharge', [], $payload));
    }

    public function simsBalances(Request $request)
    {
        $query = array_filter($request->only(['msisdn', 'iccid', 'imsi']), fn ($v) => ! is_null($v) && $v !== '');
        return $this->proxy($this->vodacom->get('/api/sims-balances', $query));
    }

    public function rechargeCallback(Request $request): JsonResponse
    {
        $request->validate([
            'msisdn'    => 'required|string',
            'amount'    => 'required|numeric',
            'status'    => 'sometimes|string|max:30',
            'reference' => 'sometimes|string|max:100',
        ]);

        $esim = Esim::findByMsisdn($request->msisdn);

        if (! $esim) {
            return response()->json(['success' => false, 'message' => 'SIM not found'], 404);
        }

        $assignment = UserEsim::where('esim_id', $esim->id)->first();

        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'No user assignment for this SIM'], 404);
        }

        $assignment->update([
            'last_recharge_amount'    => $request->amount,
            'last_recharge_reference' => $request->input('reference'),
            'last_recharge_status'    => $request->input('status', 'SUCCESS'),
            'last_recharged_at'       => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Recharge recorded']);
    }

    public function simsBalancesCallback(Request $request): JsonResponse
    {
        $request->validate([
            'msisdn'           => 'required|string',
            'balances'         => 'required_without:balance|array',
            'balances.AIRTIME' => 'nullable|numeric',
            'balances.DATA'    => 'nullable|numeric',
            'balances.SMS'     => 'nullable|numeric',
            'balance'          => 'required_without:balances|numeric',
            'currency'         => 'sometimes|string|max:10',
        ]);

        $esim = Esim::findByMsisdn($request->msisdn);

        if (! $esim) {
            return response()->json(['success' => false, 'message' => 'SIM not found'], 404);
        }

        $assignment = UserEsim::where('esim_id', $esim->id)->first();

        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'No user assignment for this SIM'], 404);
        }

        $balances = $request->has('balances')
            ? $this->normalizeVodacomBalances($request->input('balances'))
            : ['AIRTIME' => (float) $request->balance, 'DATA' => null, 'SMS' => null];

        $assignment->update([
            'balances'           => $balances,
            'balance'            => $balances['AIRTIME'],
            'balance_currency'   => $request->input('currency', 'TZS'),
            'balance_fetched_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Balance updated']);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{AIRTIME: float|null, DATA: float|null, SMS: float|null}
     */
    private function normalizeVodacomBalances(array $raw): array
    {
        $normalized = [];

        foreach (['AIRTIME', 'DATA', 'SMS'] as $key) {
            $value = $raw[$key] ?? null;
            $normalized[$key] = ($value === null || $value === '') ? null : (float) $value;
        }

        return $normalized;
    }

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}

