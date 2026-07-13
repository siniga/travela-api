<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_register_stores_verification_code_and_leaves_email_unverified(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'buyer@example.com',
            'password' => 'password123',
            'device' => 'web',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonPath('verification_email_sent', true)
            ->assertJsonPath('user.email_verified', false);

        $user = User::where('email', 'buyer@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->email_verification_code);
        $this->assertNotNull($user->email_verification_expires_at);
        $this->assertTrue($user->email_verification_expires_at->isFuture());

        Mail::assertSent(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use ($user) {
            return Hash::check($mail->code, (string) $user->fresh()->email_verification_code);
        });
    }

    public function test_verify_email_accepts_valid_code(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'buyer@example.com',
            'password' => 'password123',
            'device' => 'web',
        ])->assertCreated();

        $user = User::where('email', 'buyer@example.com')->firstOrFail();
        $code = '123456';
        $user->update([
            'email_verification_code' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/auth/verify-email', [
            'email' => 'buyer@example.com',
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Email verified successfully');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_code);
        $this->assertNull($user->email_verification_expires_at);
    }

    public function test_resend_stores_new_code_without_auto_verifying(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'buyer@example.com',
            'password' => 'password123',
            'device' => 'web',
        ])->assertCreated();

        $token = $register->json('token');
        $oldCodeHash = User::where('email', 'buyer@example.com')->value('email_verification_code');

        $response = $this->postJson('/api/auth/email/resend', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Verification code sent to your email');

        $user = User::where('email', 'buyer@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame($oldCodeHash, $user->email_verification_code);
        $this->assertNotNull($user->email_verification_expires_at);

        Mail::assertSent(EmailVerificationCodeMail::class, 2);
    }
}
