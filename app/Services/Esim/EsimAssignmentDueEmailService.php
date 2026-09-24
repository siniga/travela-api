<?php

namespace App\Services\Esim;

use App\Mail\EsimAssignmentDueMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EsimAssignmentDueEmailService
{
    /**
     * Email once per activation date. A later date sends again when that date is due.
     */
    public function sendIfEligible(Order $order, string $activationDate): bool
    {
        $order->refresh();
        $order->loadMissing('user');

        $meta = $this->metadata($order);
        if (($meta['assignment_due_email_for'] ?? null) === $activationDate) {
            return false;
        }

        $user = $order->user;
        $email = trim((string) ($user?->email ?? ''));
        if ($email === '') {
            return false;
        }

        $orderReference = $order->draft_id ?: $order->payment_reference;

        try {
            Mail::to($email)->send(new EsimAssignmentDueMail(
                customerName: $this->customerName($user?->name),
                activationDate: $activationDate,
                orderReference: $orderReference,
                dashboardUrl: $this->customerDashboardUrl(),
            ));
        } catch (Throwable $e) {
            Log::error('Failed to send eSIM assignment-due email', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $meta['assignment_due_email_for'] = $activationDate;
        $order->update(['metadata' => $meta]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Order $order): array
    {
        $value = $order->metadata;

        return is_array($value) ? $value : [];
    }

    private function customerName(?string $name): string
    {
        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : 'Traveller';
    }

    private function customerDashboardUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://thetravela.com'), '/');

        if ($base === '' || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
            $base = 'https://thetravela.com';
        }

        return $base.'/dashboard';
    }
}
