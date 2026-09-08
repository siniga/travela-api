<?php

namespace Tests\Unit;

use App\Services\EvPay\EvPayClientService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class EvPayClientServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evpay.base_url' => 'https://api-uat.evpay.co.tz',
            'services.evpay.client_id' => 'test-client-id',
            'services.evpay.client_secret' => 'test-client-secret',
            'services.evpay.signing_key' => 'test-signing-key',
        ]);

        Cache::flush();
    }

    public function test_get_access_token_authenticates_and_caches(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
        ]);

        $service = app(EvPayClientService::class);

        $this->assertSame('tok-abc', $service->getAccessToken());
        $this->assertSame('tok-abc', $service->getAccessToken());

        Http::assertSentCount(1);
    }

    public function test_get_access_token_fails_when_auth_is_rejected(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'message' => 'Invalid client',
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EvPay authentication failed: Invalid client');

        app(EvPayClientService::class)->getAccessToken();
    }

    public function test_create_card_payment_signs_and_sends_the_same_json_body(): void
    {
        Http::fake([
            'https://api-uat.evpay.co.tz/api/auth/v1' => Http::response([
                'data' => [
                    'accessToken' => 'tok-abc',
                    'expiresIn' => 36000,
                ],
            ], 200),
            'https://api-uat.evpay.co.tz/api/v1/payment/card' => Http::response([
                'status' => 'PENDING',
                'data' => ['paymentId' => 'pay-1'],
            ], 200),
        ]);

        $payload = [
            'amount' => '1000.00',
            'currency' => 'TZS',
            'reference' => 'ORD-1',
            'callbackUrl' => 'https://api.test/api/evpay/webhook',
        ];
        $rawBody = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $result = app(EvPayClientService::class)->createCardPayment($payload, 'idem-123');

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('pay-1', $result['data']['paymentId']);

        Http::assertSent(function ($request) use ($rawBody) {
            if (! str_ends_with($request->url(), '/api/v1/payment/card')) {
                return false;
            }

            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-signing-key');

            return $request->body() === $rawBody
                && $request->header('Idempotency-Key')[0] === 'idem-123'
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && strlen($request->header('X-Timestamp')[0] ?? '') >= 13
                && (int) ($request->header('X-Timestamp')[0] ?? 0) > 1_000_000_000_000
                && hash_equals($expected, $signature);
        });
    }

    public function test_create_card_payment_fails_without_signing_key(): void
    {
        config(['services.evpay.signing_key' => '']);

        Cache::put('evpay_access_token', 'tok-abc', 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EvPay signing key is not configured.');

        app(EvPayClientService::class)->createCardPayment(['amount' => '1']);
    }

    public function test_get_payment_signs_get_request(): void
    {
        Cache::put('evpay_access_token', 'tok-abc', 60);

        Http::fake([
            'https://api-uat.evpay.co.tz/api/v1/payment/pay-1' => Http::response([
                'status' => 'SUCCESS',
                'data' => [
                    'id' => 'pay-1',
                    'status' => 'SUCCESS',
                ],
            ], 200),
        ]);

        $result = app(EvPayClientService::class)->getPayment('pay-1');

        $this->assertSame('SUCCESS', $result['status']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/v1/payment/pay-1')) {
                return false;
            }

            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.', 'test-signing-key');

            return $request->method() === 'GET'
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && strlen($timestamp) >= 13
                && hash_equals($expected, $signature);
        });
    }
}
