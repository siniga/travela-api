<?php

namespace App\Services;

use App\Models\Esim;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SimAssignmentService
{
    public function __construct(
        private readonly UserEsimOrderLinkService $esimOrderLink,
        private readonly OrderRechargeService $orderRecharge,
        private readonly EsimActivationEmailService $activationEmail,
    ) {
    }

    public function orderIsPaid(Order $order): bool
    {
        return $order->payment_status === 'paid' || $order->status === 'paid';
    }

    public function orderSimType(Order $order): ?string
    {
        $meta = $this->orderMetadata($order);
        $simType = $meta['simType'] ?? null;

        return in_array($simType, [Esim::SIM_TYPE_ESIM, Esim::SIM_TYPE_PHYSICAL], true)
            ? $simType
            : null;
    }

    /**
     * After payment: auto-assign eSIM inventory only. Physical orders wait for an agent.
     *
     * @return array{
     *     assigned: bool,
     *     sim_type: ?string,
     *     reason: string,
     *     assignment?: UserEsim,
     *     recharge?: array<string, mixed>|null
     * }
     */
    public function assignForPaidOrder(Order $order): array
    {
        $order->refresh();

        if (! $this->orderIsPaid($order)) {
            return [
                'assigned' => false,
                'sim_type' => $this->orderSimType($order),
                'reason' => 'payment_not_paid',
            ];
        }

        $simType = $this->orderSimType($order);
        if (! $simType) {
            return [
                'assigned' => false,
                'sim_type' => null,
                'reason' => 'sim_type_missing',
            ];
        }

        if ($simType === Esim::SIM_TYPE_PHYSICAL) {
            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_PHYSICAL,
                'reason' => 'physical_requires_agent',
            ];
        }

        $existing = $this->findAssignmentForOrder($order);
        if ($existing) {
            return [
                'assigned' => true,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'already_assigned',
                'assignment' => $existing->loadMissing(['esim', 'bundle', 'order', 'orderItem']),
            ];
        }

        try {
            $assignment = $this->assignEsimFromInventory($order);
        } catch (\Throwable $e) {
            Log::warning('eSIM auto-assign failed after payment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'assigned' => false,
                'sim_type' => Esim::SIM_TYPE_ESIM,
                'reason' => 'no_esim_inventory',
            ];
        }

        $recharge = $this->rechargeOrderSafely($order);

        return [
            'assigned' => true,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'reason' => 'assigned',
            'assignment' => $assignment,
            'recharge' => $recharge,
        ];
    }

    /**
     * Agent assigns a specific physical ICCID after confirming the order is paid.
     */
    public function assignPhysicalSimToPaidOrder(Order $order, Esim $esim): UserEsim
    {
        $order->refresh();
        $esim->refresh();

        if (! $this->orderIsPaid($order)) {
            throw ValidationException::withMessages([
                'draft_id' => ['Order must be paid before assigning a SIM.'],
            ]);
        }

        if ($this->orderSimType($order) !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'simType' => ['This order is not a physical SIM order.'],
            ]);
        }

        if ($esim->sim_type !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'iccid' => ['The selected inventory record is not a physical SIM.'],
            ]);
        }

        if (UserEsim::where('esim_id', $esim->id)->exists()) {
            throw ValidationException::withMessages([
                'iccid' => ['This SIM is already assigned to another user.'],
            ]);
        }

        $existingForOrder = $this->findAssignmentForOrder($order);
        if ($existingForOrder) {
            throw ValidationException::withMessages([
                'draft_id' => ['This order already has a SIM assignment.'],
            ]);
        }

        if (! $order->user_id) {
            throw ValidationException::withMessages([
                'draft_id' => ['Order has no customer user_id.'],
            ]);
        }

        $assignment = DB::transaction(function () use ($order, $esim) {
            $lockedEsim = Esim::query()->whereKey($esim->id)->lockForUpdate()->firstOrFail();

            if (UserEsim::where('esim_id', $lockedEsim->id)->exists()) {
                throw ValidationException::withMessages([
                    'iccid' => ['This SIM was just assigned by another request.'],
                ]);
            }

            $assignment = UserEsim::create([
                'user_id' => $order->user_id,
                'esim_id' => $lockedEsim->id,
            ]);

            $lockedEsim->update(['status' => 'MANAGED']);

            return $this->esimOrderLink->linkAssignmentToOrder($assignment, $order)
                ->load(['esim', 'bundle', 'order', 'orderItem']);
        });

        $this->updateOrderSimMetadata($order, $assignment);

        return $assignment;
    }

    /**
     * Self-service fallback for eSIM orders only (paid + simType esim).
     */
    public function assignEsimForUserIfEligible(int $userId): array
    {
        $order = $this->esimOrderLink->latestPaidOrderWithBundles($userId);
        if (! $order) {
            throw ValidationException::withMessages([
                'payment' => ['No paid order found. Complete payment before requesting a SIM.'],
            ]);
        }

        if ($this->orderSimType($order) === Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'simType' => ['Physical SIM orders are assigned by an agent at pickup.'],
            ]);
        }

        if ($this->orderSimType($order) !== Esim::SIM_TYPE_ESIM) {
            throw ValidationException::withMessages([
                'simType' => ['Order simType must be esim for self-service assignment.'],
            ]);
        }

        return $this->assignForPaidOrder($order);
    }

    public function findAssignmentForOrder(Order $order): ?UserEsim
    {
        $meta = $this->orderMetadata($order);

        if (! empty($meta['user_esim_id'])) {
            $assignment = UserEsim::with('esim')->find($meta['user_esim_id']);
            if ($assignment) {
                return $assignment;
            }
        }

        return UserEsim::query()
            ->where('order_id', $order->id)
            ->with(['esim', 'bundle', 'order', 'orderItem'])
            ->first();
    }

    public function availableCount(string $simType = Esim::SIM_TYPE_ESIM): int
    {
        return $this->nextAvailableQuery($simType)->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentSummary(?array $result): array
    {
        if (! $result) {
            return [
                'assigned' => false,
                'sim_type' => null,
                'reason' => 'not_processed',
            ];
        }

        $summary = [
            'assigned' => (bool) ($result['assigned'] ?? false),
            'sim_type' => $result['sim_type'] ?? null,
            'reason' => $result['reason'] ?? 'unknown',
        ];

        if (! empty($result['assignment']) && $result['assignment'] instanceof UserEsim) {
            $assignment = $result['assignment'];
            $summary['user_esim_id'] = $assignment->id;
            $summary['msisdn'] = $assignment->esim?->msisdn;
            $summary['iccid'] = $assignment->esim?->iccid;
        }

        return $summary;
    }

    private function assignEsimFromInventory(Order $order): UserEsim
    {
        if (! $order->user_id) {
            throw new \RuntimeException('Order has no user_id.');
        }

        return DB::transaction(function () use ($order) {
            $existing = UserEsim::query()
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $assignment = $this->esimOrderLink->linkAssignmentToOrder($existing, $order)
                    ->load(['esim', 'bundle', 'order', 'orderItem']);
                $this->notifyActivationEmail($assignment);

                return $assignment;
            }

            $esim = $this->nextAvailableQuery(Esim::SIM_TYPE_ESIM)->lockForUpdate()->first();
            if (! $esim || UserEsim::where('esim_id', $esim->id)->exists()) {
                throw new \RuntimeException('No available eSIM inventory.');
            }

            $assignment = UserEsim::create([
                'user_id' => $order->user_id,
                'esim_id' => $esim->id,
            ]);

            $esim->update(['status' => 'MANAGED']);

            $assignment = $this->esimOrderLink->linkAssignmentToOrder($assignment, $order);
            $this->updateOrderSimMetadata($order, $assignment);
            $assignment = $assignment->load(['esim', 'bundle', 'order', 'orderItem']);
            $this->notifyActivationEmail($assignment);

            return $assignment;
        });
    }

    private function notifyActivationEmail(UserEsim $assignment): void
    {
        try {
            $this->activationEmail->sendIfEligible($assignment);
        } catch (\Throwable $e) {
            Log::warning('eSIM activation email dispatch failed', [
                'user_esim_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function nextAvailableQuery(string $simType)
    {
        return Esim::query()
            ->where('sim_type', $simType)
            ->whereNotNull('msisdn')
            ->where('msisdn', '!=', '')
            ->where('provider_status', Esim::PROVIDER_STATUS_ACTIVE)
            ->whereNotIn('id', UserEsim::query()->select('esim_id'))
            ->orderBy('id');
    }

    private function updateOrderSimMetadata(Order $order, UserEsim $assignment): void
    {
        $assignment->loadMissing('esim');
        $meta = $this->orderMetadata($order);
        $meta['user_esim_id'] = $assignment->id;
        $meta['esim_id'] = $assignment->esim_id;
        if ($assignment->esim?->msisdn) {
            $meta['msisdn'] = $assignment->esim->msisdn;
        }

        $order->update(['metadata' => $meta]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rechargeOrderSafely(Order $order): ?array
    {
        try {
            return $this->orderRecharge->rechargePaidOrder($order->fresh());
        } catch (\Throwable $e) {
            Log::error('Order recharge failed after SIM assignment', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
                'recharge_status' => 'failed',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderMetadata(Order $order): array
    {
        $raw = $order->getAttributes()['metadata'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        $value = $order->metadata;

        return is_array($value) ? $value : [];
    }
}
