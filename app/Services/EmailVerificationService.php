<?php

namespace App\Services;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailVerificationService
{
    public function issueCode(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'email_verification_code' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes(15),
        ])->save();

        return $code;
    }

    /**
     * @return array{sent: bool, message: string|null}
     */
    public function sendCode(User $user, string $code): array
    {
        try {
            Mail::to($user->email)->send(new EmailVerificationCodeMail($code));

            return ['sent' => true, 'message' => null];
        } catch (Throwable $e) {
            Log::error('Failed to send email verification code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'message' => 'We could not send the verification email. Please use resend code or try again shortly.',
            ];
        }
    }

    /**
     * @return array{sent: bool, message: string|null}
     */
    public function issueAndSend(User $user): array
    {
        $code = $this->issueCode($user);

        return $this->sendCode($user, $code);
    }
}
