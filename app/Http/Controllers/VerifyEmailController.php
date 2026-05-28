<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Mail\EmailVerificationCodeMail;

class VerifyEmailController extends Controller
{
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

        // Generate a 6-digit verification code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store the hashed code and expiration time
        $user->update([
            'email_verification_code' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes(15)
        ]);
        
        // Send verification email with the code
        Mail::to($user->email)->send(new EmailVerificationCodeMail($code));
        
        return response()->json(['message' => 'Verification code sent to your email']);
    }
}
