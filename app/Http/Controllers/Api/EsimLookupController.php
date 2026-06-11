<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Esim;
use App\Models\UserEsim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EsimLookupController extends Controller
{
    /**
     * Autocomplete: match ICCID by the last digits the user is typing.
     *
     * Query: iccid or q (digits only), optional sim_type, available_only, limit
     */
    public function searchByIccidSuffix(Request $request): JsonResponse
    {
        $suffix = $this->normalizeIccidSuffix(
            (string) ($request->query('iccid') ?? $request->query('q') ?? '')
        );

        $minLength = 4;

        if ($suffix === '') {
            return response()->json([
                'success' => false,
                'message' => 'Provide iccid or q query parameter (last digits of the ICCID).',
                'min_length' => $minLength,
                'suggestions' => [],
            ], 422);
        }

        if (strlen($suffix) < $minLength) {
            return response()->json([
                'success' => true,
                'query' => $suffix,
                'min_length' => $minLength,
                'suggestions' => [],
            ]);
        }

        $limit = min(max((int) $request->query('limit', 10), 1), 20);
        $simType = strtolower((string) $request->query('sim_type', Esim::SIM_TYPE_PHYSICAL));
        $availableOnly = $request->boolean('available_only');

        $likeSuffix = '%'.$this->escapeLike($suffix);

        $query = Esim::query()
            ->whereNotNull('iccid')
            ->where('iccid', 'like', $likeSuffix)
            ->orderBy('iccid');

        if (in_array($simType, [Esim::SIM_TYPE_PHYSICAL, Esim::SIM_TYPE_ESIM], true)) {
            $query->where('sim_type', $simType);
        }

        if ($availableOnly) {
            $query->whereNotIn('id', UserEsim::query()->select('esim_id'));
        }

        $esims = $query->limit($limit)->get();

        $assignedEsimIds = UserEsim::query()
            ->whereIn('esim_id', $esims->pluck('id'))
            ->pluck('esim_id')
            ->flip();

        $suggestions = $esims->map(fn (Esim $esim) => [
            'id' => $esim->id,
            'iccid' => $esim->iccid,
            'iccid_suffix' => $esim->iccid ? substr($esim->iccid, -strlen($suffix)) : null,
            'msisdn' => $esim->msisdn,
            'sim_type' => $esim->sim_type,
            'status' => $esim->status,
            'provider_status' => $esim->provider_status,
            'is_assigned' => isset($assignedEsimIds[$esim->id]),
            'label' => trim(($esim->iccid ?? '').($esim->msisdn ? ' · '.$esim->msisdn : '')),
            'value' => $esim->iccid,
        ])->values();

        return response()->json([
            'success' => true,
            'query' => $suffix,
            'min_length' => $minLength,
            'count' => $suggestions->count(),
            'suggestions' => $suggestions,
        ]);
    }

    private function normalizeIccidSuffix(string $value): string
    {
        return preg_replace('/\D+/', '', trim($value)) ?? '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
