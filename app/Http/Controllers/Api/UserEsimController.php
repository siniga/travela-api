<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeEsimRechargeRequest;
use App\Models\UserEsim;
use App\Services\VodacomSimManagerService;
use Illuminate\Http\Request;

class UserEsimController extends Controller
{
    public function __construct(private readonly VodacomSimManagerService $vodacom)
    {
    }

    public function index(Request $request)
    {
        $esims = $request->user()
            ->esims()
            ->with('esim')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'esims' => $esims->map(function (UserEsim $assignment) {
                return [
                    'id' => $assignment->id,
                    'esim_id' => $assignment->esim_id,
                    'msisdn' => $assignment->esim?->msisdn,
                    'network_id' => $assignment->esim?->network_id,
                    'iccid' => $assignment->esim?->iccid,
                    'imsi' => $assignment->esim?->imsi,
                    'description' => $assignment->esim?->description,
                    'status' => $assignment->esim?->status,
                    'created_at' => $assignment->created_at,
                ];
            })->values(),
        ]);
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

        $payload = array_filter($request->only(['airtime_amount', 'msisdn', 'network_id', 'reference', 'product_id']), fn ($v) => $v !== null && $v !== '');
        return $this->proxy($this->vodacom->post('/api/recharge', [], $payload));
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

