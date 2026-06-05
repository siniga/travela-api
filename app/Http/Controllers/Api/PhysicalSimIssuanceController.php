<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserEsim;
use App\Services\PhysicalSimIssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhysicalSimIssuanceController extends Controller
{
    public function __construct(
        private readonly PhysicalSimIssuanceService $issuance,
    ) {
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
}
