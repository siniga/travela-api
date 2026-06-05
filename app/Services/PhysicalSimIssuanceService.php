<?php

namespace App\Services;

use App\Models\Esim;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhysicalSimIssuanceService
{
    /**
     * Mark a physical SIM as handed over to the customer.
     *
     * @param  array{draft_id?: string, order_id?: int, user_esim_id?: int, msisdn?: string, iccid?: string, location?: string}  $input
     * @return array{assignment: UserEsim, already_issued: bool}
     */
    public function issueForOrder(array $input, User $issuedBy): array
    {
        $order = $this->resolveOrder($input);
        $assignment = $this->resolveAssignmentForOrder($order, $input);

        return $this->issueAssignment($assignment, $issuedBy, $input['location'] ?? null);
    }

    /**
     * @return array{assignment: UserEsim, already_issued: bool}
     */
    public function issueAssignment(UserEsim $assignment, User $issuedBy, ?string $location = null): array
    {
        $assignment->loadMissing(['esim', 'user', 'physicalIssuedBy']);

        $this->assertPhysicalSim($assignment);

        if ($assignment->physical_issued_at) {
            return ['assignment' => $assignment, 'already_issued' => true];
        }

        if (! $location && $issuedBy->relationLoaded('agentLocation')) {
            $location = $issuedBy->agentLocation?->current_location;
        } elseif (! $location) {
            $issuedBy->loadMissing('agentLocation');
            $location = $issuedBy->agentLocation?->current_location;
        }

        $assignment = DB::transaction(function () use ($assignment, $issuedBy, $location) {
            $locked = UserEsim::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($locked->physical_issued_at) {
                return $locked->load(['esim', 'user', 'physicalIssuedBy']);
            }

            $locked->update([
                'physical_issued_at' => now(),
                'physical_issued_by' => $issuedBy->id,
                'physical_issued_location' => $location,
            ]);

            return $locked->fresh(['esim', 'user', 'physicalIssuedBy']);
        });

        return ['assignment' => $assignment, 'already_issued' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public function issuancePayload(UserEsim $assignment): array
    {
        $assignment->loadMissing(['esim', 'physicalIssuedBy']);

        return [
            'physical_issued' => (bool) $assignment->physical_issued_at,
            'physical_issued_at' => $assignment->physical_issued_at,
            'physical_issued_by' => $assignment->physical_issued_by,
            'physical_issued_location' => $assignment->physical_issued_location,
            'physical_issued_agent' => $assignment->physicalIssuedBy
                ? $assignment->physicalIssuedBy->only(['id', 'name', 'email'])
                : null,
            'sim_type' => $assignment->esim?->sim_type,
            'requires_physical_handover' => $assignment->esim?->sim_type === Esim::SIM_TYPE_PHYSICAL,
        ];
    }

    /**
     * @param  array{draft_id?: string, order_id?: int}  $input
     */
    private function resolveOrder(array $input): Order
    {
        if (! empty($input['order_id'])) {
            $order = Order::query()->find($input['order_id']);
        } elseif (! empty($input['draft_id'])) {
            $order = Order::query()->where('draft_id', trim((string) $input['draft_id']))->first();
        } else {
            throw ValidationException::withMessages([
                'draft_id' => ['Provide draft_id or order_id.'],
            ]);
        }

        if (! $order) {
            throw ValidationException::withMessages([
                'draft_id' => ['Order not found.'],
            ]);
        }

        return $order;
    }

    /**
     * @param  array{user_esim_id?: int, msisdn?: string, iccid?: string}  $input
     */
    private function resolveAssignmentForOrder(Order $order, array $input): UserEsim
    {
        if (! empty($input['user_esim_id'])) {
            $assignment = UserEsim::with('esim')->find($input['user_esim_id']);
            if (! $assignment || (int) $assignment->user_id !== (int) $order->user_id) {
                throw ValidationException::withMessages([
                    'user_esim_id' => ['Assignment does not belong to this order customer.'],
                ]);
            }

            return $this->verifySimIdentifiers($assignment, $input);
        }

        if (! empty($input['msisdn']) || ! empty($input['iccid'])) {
            $query = UserEsim::query()
                ->with('esim')
                ->where('user_id', $order->user_id);

            if (! empty($input['msisdn'])) {
                $msisdn = (string) $input['msisdn'];
                $query->whereHas('esim', fn ($q) => $q->where('msisdn', $msisdn));
            }

            if (! empty($input['iccid'])) {
                $iccid = (string) $input['iccid'];
                $query->whereHas('esim', fn ($q) => $q->where('iccid', $iccid));
            }

            $assignment = $query->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'msisdn' => ['No assignment found for this customer with the given SIM details.'],
                ]);
            }

            return $assignment;
        }

        $assignment = UserEsim::query()
            ->with('esim')
            ->where('user_id', $order->user_id)
            ->orderByRaw('CASE WHEN order_id = ? THEN 0 ELSE 1 END', [$order->id])
            ->orderByDesc('id')
            ->first();

        if (! $assignment) {
            throw ValidationException::withMessages([
                'draft_id' => ['No SIM assignment found for this order. Assign a SIM first.'],
            ]);
        }

        return $assignment;
    }

    /**
     * @param  array{msisdn?: string, iccid?: string}  $input
     */
    private function verifySimIdentifiers(UserEsim $assignment, array $input): UserEsim
    {
        $esim = $assignment->esim;
        if (! $esim) {
            throw ValidationException::withMessages([
                'user_esim_id' => ['Assignment has no linked SIM.'],
            ]);
        }

        if (! empty($input['msisdn']) && $esim->msisdn !== (string) $input['msisdn']) {
            throw ValidationException::withMessages([
                'msisdn' => ['MSISDN does not match this assignment.'],
            ]);
        }

        if (! empty($input['iccid']) && $esim->iccid !== (string) $input['iccid']) {
            throw ValidationException::withMessages([
                'iccid' => ['ICCID does not match this assignment.'],
            ]);
        }

        return $assignment;
    }

    private function assertPhysicalSim(UserEsim $assignment): void
    {
        $simType = $assignment->esim?->sim_type;

        if ($simType !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'sim_type' => ['This assignment is not a physical SIM. Handover tracking applies to physical cards only.'],
            ]);
        }
    }
}
