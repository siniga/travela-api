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

        $escaped = $this->escapeLike($suffix);
        $likeSuffix = '%'.$escaped;
        $likePrefix = $escaped.'%';
        $likeContains = '%'.$escaped.'%';

        $query = Esim::query()
            ->whereNotNull('iccid')
            ->where(function ($q) use ($suffix, $likeSuffix, $likePrefix, $likeContains) {
                $q->where('iccid', 'like', $likeSuffix)
                    ->orWhere('iccid', 'like', $likePrefix)
                    ->orWhere('iccid', $suffix);

                // Longer input is often a full ICCID scan — allow substring match too.
                if (strlen($suffix) >= 10) {
                    $q->orWhere('iccid', 'like', $likeContains);
                }

                // Agents sometimes type the phone number on the card, not ICCID.
                $q->orWhere(function ($msisdnQ) use ($likeSuffix, $likePrefix) {
                    $msisdnQ->whereNotNull('msisdn')
                        ->where(function ($inner) use ($likeSuffix, $likePrefix) {
                            $inner->where('msisdn', 'like', $likeSuffix)
                                ->orWhere('msisdn', 'like', $likePrefix);
                        });
                });
            })
            ->orderBy('iccid');

        if (in_array($simType, [Esim::SIM_TYPE_PHYSICAL, Esim::SIM_TYPE_ESIM], true)) {
            $query->where('sim_type', $simType);
        }

        if ($availableOnly) {
            $query->whereNotIn('id', UserEsim::query()->select('esim_id'));
        }

        $esims = $query->limit($limit)->get();

        $assignedExcluded = 0;
        if ($availableOnly && $esims->isEmpty()) {
            $assignedExcluded = $this->matchingCount($suffix, $simType, true);
        }

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
            'assigned_matches_excluded' => $assignedExcluded,
            'hint' => $assignedExcluded > 0
                ? 'Matching SIM(s) exist but are already assigned. Try another card or search without available_only.'
                : null,
            'suggestions' => $suggestions,
        ]);
    }

    private function matchingCount(string $suffix, string $simType, bool $assignedOnly): int
    {
        $escaped = $this->escapeLike($suffix);
        $likeSuffix = '%'.$escaped;
        $likePrefix = $escaped.'%';
        $likeContains = '%'.$escaped.'%';

        $query = Esim::query()
            ->whereNotNull('iccid')
            ->where(function ($q) use ($suffix, $likeSuffix, $likePrefix, $likeContains) {
                $q->where('iccid', 'like', $likeSuffix)
                    ->orWhere('iccid', 'like', $likePrefix)
                    ->orWhere('iccid', $suffix);

                if (strlen($suffix) >= 10) {
                    $q->orWhere('iccid', 'like', $likeContains);
                }

                $q->orWhere(function ($msisdnQ) use ($likeSuffix, $likePrefix) {
                    $msisdnQ->whereNotNull('msisdn')
                        ->where(function ($inner) use ($likeSuffix, $likePrefix) {
                            $inner->where('msisdn', 'like', $likeSuffix)
                                ->orWhere('msisdn', 'like', $likePrefix);
                        });
                });
            });

        if (in_array($simType, [Esim::SIM_TYPE_PHYSICAL, Esim::SIM_TYPE_ESIM], true)) {
            $query->where('sim_type', $simType);
        }

        if ($assignedOnly) {
            $query->whereIn('id', UserEsim::query()->select('esim_id'));
        }

        return $query->count();
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
