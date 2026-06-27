<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
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

        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Mail::to($user->email)->send(new EmailVerificationCodeMail($code));

        $user->update([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
        ]);
        $user->refresh();

        event(new Verified($user));

        $token = $user->createToken($req->device ?? 'mobile')->plainTextToken;

        return response()->json([
            'user'  => $this->userPayload($user),
            'token' => $token,
            'email_verification_required' => false,
        ], 201);
    }

    public function login(LoginRequest $req) {
        $user = User::query()
            ->with('agentLocation')
            ->where('email', $req->email)
            ->first();
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
        $user = $req->user();
        if ($user) {
            $user->loadMissing('agentLocation');
        }

        return response()->json(['user' => $this->userPayload($user)]);
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

        $payload = $user->only(['id', 'name', 'email', 'role']);

        if ($user->role === 'agent') {
            $payload['agent_location'] = $user->agentLocation
                ? $user->agentLocation->only([
                    'id',
                    'phone',
                    'work_station',
                    'current_location',
                    'current_location_updated_at',
                ])
                : null;
        }

        return $payload;
    }
}
