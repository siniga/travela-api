<?php

namespace App\Services;

use App\Mail\WalkInCustomerLoginMail;
use App\Models\Esim;
use App\Models\User;
use App\Models\UserEsim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WalkInPhysicalSimService
{
    /**
     * Assign an unassigned physical SIM to a walk-in customer (no order required).
     *
     * @param  array{
     *     esim_id: int,
     *     iccid?: string,
     *     msisdn?: string,
     *     customer_name: string,
     *     customer_email: string,
     *     location?: string|null
     * }  $input
     * @return array{
     *     user_id: int,
     *     esim_id: int,
     *     msisdn: ?string,
     *     iccid: ?string,
     *     email_sent: bool,
     *     user_created: bool
     * }
     */
    public function assign(array $input, User $agent): array
    {
        $esim = Esim::query()->find($input['esim_id']);
        if (! $esim) {
            throw new NotFoundHttpException('SIM not found.');
        }

        $this->assertWalkInEsim($esim, $input);

        if ($esim->isAssigned()) {
            throw new ConflictHttpException('SIM already assigned.');
        }

        $userCreated = false;

        $assignment = DB::transaction(function () use ($input, $agent, $esim, &$userCreated) {
            $lockedEsim = Esim::query()->whereKey($esim->id)->lockForUpdate()->firstOrFail();

            if (UserEsim::query()->where('esim_id', $lockedEsim->id)->exists()) {
                throw new ConflictHttpException('SIM was just assigned by another request.');
            }

            $this->assertWalkInEsim($lockedEsim, $input);

            [$user, $userCreated] = $this->findOrCreateCustomer(
                $input['customer_email'],
                $input['customer_name'],
            );

            $assignment = UserEsim::create([
                'user_id' => $user->id,
                'esim_id' => $lockedEsim->id,
                'physical_issued_at' => now(),
                'physical_issued_by' => $agent->id,
                'physical_issued_location' => $input['location'] ?? null,
            ]);

            $lockedEsim->update([
                'status' => 'MANAGED',
                'sale_status' => Esim::SALE_STATUS_SOLD,
            ]);

            return $assignment->load('esim');
        });

        $emailSent = $this->sendLoginEmail(
            User::query()->findOrFail($assignment->user_id),
            $input['customer_name'],
        );

        return [
            'user_id' => $assignment->user_id,
            'esim_id' => $assignment->esim_id,
            'msisdn' => $assignment->esim?->msisdn,
            'iccid' => $assignment->esim?->iccid,
            'email_sent' => $emailSent,
            'user_created' => $userCreated,
        ];
    }

    /**
     * @param  array{iccid?: string, msisdn?: string}  $input
     */
    private function assertWalkInEsim(Esim $esim, array $input): void
    {
        if ($esim->sim_type !== Esim::SIM_TYPE_PHYSICAL) {
            throw ValidationException::withMessages([
                'esim_id' => ['The selected SIM is not a physical SIM.'],
            ]);
        }

        if (! empty($input['iccid']) && $esim->iccid !== trim((string) $input['iccid'])) {
            throw ValidationException::withMessages([
                'iccid' => ['ICCID does not match the selected inventory record.'],
            ]);
        }

        if (! empty($input['msisdn'])) {
            $normalized = Esim::normalizeMsisdn((string) $input['msisdn']);
            $esimMsisdn = Esim::normalizeMsisdn((string) $esim->msisdn);

            if ($esimMsisdn !== $normalized) {
                throw ValidationException::withMessages([
                    'msisdn' => ['MSISDN does not match the selected inventory record.'],
                ]);
            }
        }
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function findOrCreateCustomer(string $email, string $name): array
    {
        $email = strtolower(trim($email));

        $existing = User::query()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if ($existing->role !== 'user') {
                throw ValidationException::withMessages([
                    'customer_email' => ['This email belongs to a non-customer account and cannot be used for walk-in registration.'],
                ]);
            }

            return [$existing, false];
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => 'user',
            'password' => Hash::make(Str::password(32)),
            'email_verified_at' => now(),
        ]);

        return [$user, true];
    }

    private function sendLoginEmail(User $user, string $customerName): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
            'reset_code' => Hash::make($code),
            'reset_code_expires_at' => now()->addMinutes(15),
        ]);

        try {
            Mail::to($user->email)->send(new WalkInCustomerLoginMail($customerName, $code));

            return true;
        } catch (\Throwable $e) {
            Log::error('Walk-in customer login email failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
