<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EsimActivateRequest;
use App\Http\Requests\EsimRechargeRequest;
use App\Http\Requests\EsimSuspendRequest;
use App\Services\VodacomSimManagerService;
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

    private function proxy($vodacomResponse)
    {
        $contentType = $vodacomResponse->header('Content-Type', 'application/json');
        $body = $vodacomResponse->body();

        return response($body, $vodacomResponse->status())
            ->header('Content-Type', $contentType ?: 'application/json');
    }
}

