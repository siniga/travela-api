<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\EmailVerificationService;

class VerifyEmailController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerification,
    ) {
    }

    public function verify(Request $request) {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired verification code'], 422);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified'], 200);
        }

        if (
            ! $user->email_verification_code
            || ! $user->email_verification_expires_at
            || ! Hash::check($request->code, $user->email_verification_code)
            || $user->email_verification_expires_at < now()
        ) {
            return response()->json(['message' => 'Invalid or expired verification code'], 422);
        }

        // Mark email as verified and clear verification code
        $user->update([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null
        ]);
        
        // Refresh the user model to get updated data
        $user->refresh();

        event(new Verified($user));

        return response()->json(['message' => 'Email verified successfully']);
    }

    public function resend(Request $request) {
        $user = $request->user();
        
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $mailResult = $this->emailVerification->issueAndSend($user);

        if (! $mailResult['sent']) {
            return response()->json(['message' => $mailResult['message']], 503);
        }

        return response()->json(['message' => 'Verification code sent to your email']);
    }
}
