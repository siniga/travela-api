<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerification,
    ) {
    }

    public function register(RegisterRequest $req) {
        $user = User::create([
            'name' => $req->name,
            'email' => $req->email,
            'role' => 'user',
            'password' => $req->password,
        ]);

        $mailResult = $this->emailVerification->issueAndSend($user);

        $token = $user->createToken($req->device ?? 'mobile')->plainTextToken;

        $response = [
            'user'  => $this->userPayload($user->fresh()),
            'token' => $token,
            'email_verification_required' => true,
        ];

        if (! $mailResult['sent']) {
            $response['message'] = $mailResult['message'];
            $response['verification_email_sent'] = false;
        } else {
            $response['verification_email_sent'] = true;
        }

        return response()->json($response, 201);
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
        $payload['email_verified'] = $user->hasVerifiedEmail();

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
