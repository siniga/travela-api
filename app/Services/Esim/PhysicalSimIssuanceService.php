<?php

namespace App\Services\Esim;

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
     * Physical SIMs handed to the customer, split by activation confirmation.
     *
     * @return array{
     *     issued: list<array<string, mixed>>,
     *     completed: list<array<string, mixed>>,
     *     issued_count: int,
     *     completed_today_count: int
     * }
     */
    public function issuedPhysicalQueue(int $limit = 100): array
    {
        $base = UserEsim::query()
            ->whereNotNull('physical_issued_at')
            ->whereNotNull('order_id')
            ->whereHas('esim', fn ($q) => $q->where('sim_type', Esim::SIM_TYPE_PHYSICAL));

        $issuedCount = (clone $base)->whereNull('device_activated_at')->count();

        $issued = (clone $base)
            ->with(['esim', 'user', 'order.user', 'order.orderItems'])
            ->whereNull('device_activated_at')
            ->orderByDesc('physical_issued_at')
            ->limit($limit)
            ->get();

        $completed = (clone $base)
            ->with(['esim', 'user', 'order.user', 'order.orderItems'])
            ->whereNotNull('device_activated_at')
            ->orderByDesc('device_activated_at')
            ->limit($limit)
            ->get();

        $completedTodayCount = (clone $base)
            ->whereDate('device_activated_at', now()->toDateString())
            ->count();

        return [
            'issued' => $issued
                ->map(fn (UserEsim $row) => $this->formatIssuedRow($row))
                ->values()
                ->all(),
            'completed' => $completed
                ->map(fn (UserEsim $row) => $this->formatIssuedRow($row))
                ->values()
                ->all(),
            'issued_count' => $issuedCount,
            'completed_today_count' => $completedTodayCount,
        ];
    }

    /**
     * Agent confirms the physical SIM is in the phone and the bundle works.
     *
     * @param  array{draft_id?: string, order_id?: int}  $input
     * @return array{already_confirmed: bool, order: array<string, mixed>}
     */
    public function confirmActivationForOrder(array $input): array
    {
        $order = $this->resolveOrder($input);
        $assignment = $this->resolveAssignmentForOrder($order, $input);
        $assignment->loadMissing(['esim', 'user', 'order.user', 'order.orderItems']);
        $this->assertPhysicalSim($assignment);

        if (! $assignment->physical_issued_at) {
            throw ValidationException::withMessages([
                'draft_id' => ['Issue the physical SIM before confirming activation.'],
            ]);
        }

        $already = $assignment->device_activated_at !== null;

        if (! $already) {
            $assignment->forceFill(['device_activated_at' => now()])->save();
            $assignment->refresh();
            $assignment->loadMissing(['esim', 'user', 'order.user', 'order.orderItems']);
        }

        return [
            'already_confirmed' => $already,
            'order' => $this->formatIssuedRow($assignment),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function formatIssuedRow(UserEsim $assignment): array
    {
        $assignment->loadMissing(['esim', 'user', 'order.user', 'order.orderItems']);
        $order = $assignment->order;
        $draftId = (string) ($order?->draft_id ?? '');
        $digits = preg_replace('/\D+/', '', $draftId) ?? '';
        $suffix = $digits !== '' ? substr($digits, -3) : '';
        $customer = $order?->user ?? $assignment->user;
        $item = $order?->orderItems?->first();
        $activated = $assignment->device_activated_at;

        return [
            'draft_id' => $draftId,
            'order_id' => $order?->id,
            'order_number' => $draftId !== '' ? $draftId : null,
            'order_number_suffix' => $suffix !== '' ? $suffix : null,
            'customer_name' => $customer?->name,
            'customer_email' => $customer?->email,
            'bundle_name' => $item?->bundle_name,
            'iccid' => $assignment->esim?->iccid,
            'msisdn' => $assignment->esim?->msisdn,
            'agent_location' => $assignment->physical_issued_location,
            'issued_at' => optional($assignment->physical_issued_at)?->toIso8601String(),
            'completed_at' => optional($activated)?->toIso8601String(),
            'status' => $activated ? 'completed' : 'issued',
            'payment_status' => $order?->payment_status,
        ];
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
