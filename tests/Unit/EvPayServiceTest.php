<?php

namespace Tests\Unit;

use App\Services\EvPay\EvPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvPayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_generate_signature_uses_timestamp_dot_raw_body(): void
    {
        $service = app(EvPayService::class);
        $timestamp = '1751451332000';
        $body = '{"fsOperator":"Mpesa","amount":5000}';

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'test-sig-key');

        $this->assertSame($expected, $service->generateSignature($timestamp, $body));
    }

    public function test_get_signature_for_empty_get_body(): void
    {
        $service = app(EvPayService::class);
        $timestamp = '1751451332000';

        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.', 'test-sig-key'),
            $service->generateSignature($timestamp, ''),
        );
    }

    public function test_access_token_is_cached(): void
    {
        $authCalls = 0;

        Http::fake(function ($request) use (&$authCalls) {
            if (str_contains($request->url(), '/api/auth/v1')) {
                $authCalls++;

                return Http::response([
                    'access_token' => 'tok_cached',
                    'token_type' => 'Bearer',
                    'expires_in' => 300,
                ], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $service = app(EvPayService::class);

        $this->assertSame('tok_cached', $service->getAccessToken());
        $this->assertSame('tok_cached', $service->getAccessToken());
        $this->assertSame(1, $authCalls);
    }
}
