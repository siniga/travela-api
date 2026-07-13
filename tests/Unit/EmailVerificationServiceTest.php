<?php

namespace Tests\Unit;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class EmailVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_code_returns_false_when_mail_transport_fails(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('SMTP unavailable'));

        $user = User::factory()->create([
            'email' => 'buyer@example.com',
        ]);

        $service = app(EmailVerificationService::class);
        $result = $service->sendCode($user, '123456');

        $this->assertFalse($result['sent']);
        $this->assertNotNull($result['message']);
    }

    public function test_send_code_returns_true_when_mail_succeeds(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'buyer@example.com',
        ]);

        $service = app(EmailVerificationService::class);
        $result = $service->sendCode($user, '123456');

        $this->assertTrue($result['sent']);
        Mail::assertSent(EmailVerificationCodeMail::class);
    }
}
