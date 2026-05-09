<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmailVerificationCodeMail;

class AuthController extends Controller
{
    public function register(RegisterRequest $req) {
        $user = User::create([
            'name' => $req->name,
            'email' => $req->email,
            'role' => 'user',
            'password' => Hash::make($req->password),
        ]);

        // Generate a 6-digit verification code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store the hashed code and expiration time
        $user->update([
            'email_verification_code' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes(15)
        ]);
        
        // Send verification email with the code
        Mail::to($user->email)->send(new EmailVerificationCodeMail($code));

        // Issue token immediately; gate sensitive routes with 'verified'
        $token = $user->createToken($req->device ?? 'mobile')->plainTextToken;

        return response()->json([
            'user'  => $this->userPayload($user),
            'token' => $token,
            'email_verification_required' => true
        ], 201);
    }

    public function login(LoginRequest $req) {
        $user = User::where('email', $req->email)->first();
        if (! $user || ! Hash::check($req->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        // Optional: rotate existing token(s) for device name
        if ($req->device) {
            $user->tokens()->where('name', $req->device)->delete();
        }

        $token = $user->createToken($req->device ?? 'mobile')->plainTextToken;

        return response()->json([
            'user'  => $this->userPayload($user),
            'token' => $token,
            'email_verified' => ! is_null($user->email_verified_at),
        ]);
    }

    public function me(Request $req) {
        return response()->json(['user' => $this->userPayload($req->user())]);
    }

    public function logout(Request $req) {
        // revoke current token only
        $req->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return $user->only(['id', 'name', 'email', 'role']);
    }
}
