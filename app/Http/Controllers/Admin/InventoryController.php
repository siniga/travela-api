<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SimInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private readonly SimInventoryService $inventory)
    {
    }

    /**
     * SIM stock levels from esims + user_esims (assigned vs available).
     *
     * Query: network_id (default 1), sim_type (all|physical|esim)
     */
    public function stock(Request $request): JsonResponse
    {
        $networkId = $request->query('network_id');
        $simType = strtolower((string) $request->query('sim_type', 'all'));

        $report = $this->inventory->report(
            is_numeric($networkId) ? (int) $networkId : null,
            $simType,
        );

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }
}
