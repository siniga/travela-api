<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as Pwd;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Mail\PasswordResetCodeMail;

class PasswordResetController extends Controller
{
    public function sendLink(Request $r) {
        $r->validate(['email' => ['required','email']]);
        
        $user = User::where('email', $r->email)->first();
        if (!$user) {
            // Don't reveal if email exists or not for security
            return response()->json(['message' => 'If the email exists, a reset code has been sent'], 200);
        }
        
        // Generate a 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store the hashed code and expiration time
        $user->update([
            'reset_code' => Hash::make($code),
            'reset_code_expires_at' => now()->addMinutes(15)
        ]);
        
        $setPasswordUrl = 'https://thetravela.com/set-password?email='.urlencode($user->email);

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($code, $setPasswordUrl));
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'We could not send the reset email. Please try again shortly.',
            ], 503);
        }
        
        return response()->json(['message' => 'Reset code sent to your email']);
    }

    public function reset(Request $r) {
        $r->validate([
            'email'    => ['required','email'],
            'code'     => ['required','string','size:6'],
            'password' => ['required','confirmed', Pwd::min(8)],
        ]);

        $user = User::where('email', $r->email)->first();
        
        if (!$user || 
            !$user->reset_code || 
            !Hash::check($r->code, $user->reset_code) ||
            $user->reset_code_expires_at < now()) {
            return response()->json(['message' => 'Invalid or expired code'], 422);
        }

        // Update password and clear reset code
        $user->update([
            'password' => Hash::make($r->password),
            'reset_code' => null,
            'reset_code_expires_at' => null
        ]);
        
        // Revoke all existing tokens for security
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successful']);
    }
}
