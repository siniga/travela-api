<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\SimAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvPayMobileMoneyPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_validation_rejects_unknown_operator_and_low_amount(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'M-Pesa',
            'phone' => '0712345678',
            'amount' => 50,
        ])->assertStatus(422);

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'Mpesa',
            'phone' => '0712345678',
            'amount' => 50,
        ])->assertStatus(422);
    }

    public function test_successful_payment_initiation_stays_pending(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);
        Sanctum::actingAs($user);
        $this->fakeEvPayAuthAndUssd();

        $response = $this->postJson('/api/payments/mobile-money', [
            'operator' => 'Mpesa',
            'phone' => '0712345678',
            'amount' => 5000,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.phone_number', '255712345678');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'operator' => 'Mpesa',
            'phone_number' => '255712345678',
            'status' => 'PENDING',
            'transaction_id' => 'E110526AC14K7X2M9',
        ]);

        $this->assertNull(Payment::first()->fulfilled_at);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/payment/ussd')) {
                return false;
            }

            $body = $request->body();
            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $signature = $request->header('X-Signature')[0] ?? '';
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'test-sig-key');

            $payload = json_decode($body, true);

            return $signature === $expected
                && ($request->header('Idempotency-Key')[0] ?? null) === $payload['requestId']
                && $payload['fsOperator'] === 'Mpesa'
                && $payload['payerAccountId'] === '255712345678'
                && $payload['amount'] === 5000
                && $payload['currency'] === 'TZS';
        });
    }

    public function test_evpay_401_returns_provider_auth_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            'https://evpay.test/api/auth/v1' => Http::response([
                'access_token' => 'tok',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
            'https://evpay.test/api/v1/payment/ussd' => Http::response([
                'code' => 'authentication_required',
                'message' => 'Token expired',
            ], 401),
        ]);

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'Mpesa',
            'phone' => '0712345678',
            'amount' => 5000,
        ])->assertStatus(502)
            ->assertJsonPath('code', 'authentication_required');
    }

    public function test_evpay_422_returns_validation_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            'https://evpay.test/api/auth/v1' => Http::response([
                'access_token' => 'tok',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
            'https://evpay.test/api/v1/payment/ussd' => Http::response([
                'code' => 'validation_error',
                'message' => 'Invalid payer account',
            ], 422),
        ]);

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'TigoPesa',
            'phone' => '0712345678',
            'amount' => 5000,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'validation_error');
    }

    public function test_evpay_5xx_returns_upstream_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            'https://evpay.test/api/auth/v1' => Http::response([
                'access_token' => 'tok',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
            'https://evpay.test/api/v1/payment/ussd' => Http::response([
                'message' => 'boom',
            ], 503),
        ]);

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'HaloPesa',
            'phone' => '0712345678',
            'amount' => 5000,
        ])->assertStatus(502)
            ->assertJsonPath('code', 'upstream_error');
    }

    public function test_retry_reuses_same_request_id_and_body(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->fakeEvPayAuthAndUssd();

        $first = $this->postJson('/api/payments/mobile-money', [
            'operator' => 'AirtelMoney',
            'phone' => '0712345678',
            'amount' => 2500,
        ])->assertStatus(202);

        $requestId = $first->json('data.request_id');

        $second = $this->postJson('/api/payments/mobile-money', [
            'operator' => 'AirtelMoney',
            'phone' => '0712345678',
            'amount' => 2500,
            'request_id' => $requestId,
        ])->assertStatus(202);

        $this->assertSame($requestId, $second->json('data.request_id'));
        $this->assertSame(1, Payment::count());

        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            if (str_contains($request->url(), '/api/v1/payment/ussd')) {
                $bodies[] = $request->body();
            }

            return true;
        });

        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
    }

    public function test_status_lookup_updates_local_payment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payment = Payment::create([
            'request_id' => 'PAY-STATUS-1',
            'user_id' => $user->id,
            'provider' => 'evpay',
            'payment_method' => 'mobile_money',
            'operator' => 'Mpesa',
            'phone_number' => '255712345678',
            'amount' => 5000,
            'currency' => 'TZS',
            'status' => 'PENDING',
        ]);

        Http::fake([
            'https://evpay.test/api/auth/v1' => Http::response([
                'access_token' => 'tok',
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], 200),
            'https://evpay.test/api/v1/payment/*' => Http::response([
                'data' => [
                    'id' => 'TX-99',
                    'orderReference' => 'PAY-STATUS-1',
                    'status' => 'FAILED',
                    'description' => 'Customer cancelled',
                ],
            ], 200),
        ]);

        $this->getJson('/api/payments/'.$payment->id.'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.transaction_id', 'TX-99');
    }

    public function test_user_cannot_read_another_users_payment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $payment = Payment::create([
            'request_id' => 'PAY-OTHER',
            'user_id' => $owner->id,
            'provider' => 'evpay',
            'payment_method' => 'mobile_money',
            'operator' => 'Mpesa',
            'phone_number' => '255712345678',
            'amount' => 5000,
            'currency' => 'TZS',
            'status' => 'PENDING',
        ]);

        Sanctum::actingAs($other);
        $this->getJson('/api/payments/'.$payment->id.'/status')->assertStatus(403);
    }

    public function test_initiation_does_not_mark_order_paid(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'draft_id' => 'DRAFT-PAY-1',
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal' => 5000,
            'discount_amount' => 0,
            'total_amount' => 5000,
            'currency' => 'TZS',
        ]);

        Sanctum::actingAs($user);
        $this->fakeEvPayAuthAndUssd();

        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->never();
        });

        $this->postJson('/api/payments/mobile-money', [
            'operator' => 'Mpesa',
            'phone' => '0712345678',
            'order_id' => $order->id,
        ])->assertStatus(202);

        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
        $this->assertNotSame('paid', $order->status);
    }

    private function fakeEvPayAuthAndUssd(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/auth/v1')) {
                return Http::response([
                    'access_token' => 'tok_test',
                    'token_type' => 'Bearer',
                    'expires_in' => 300,
                ], 200);
            }

            if (str_contains($request->url(), '/api/v1/payment/ussd')) {
                $payload = json_decode($request->body(), true);

                return Http::response([
                    'success' => true,
                    'code' => 202,
                    'message' => 'Push request sent. Awaiting customer confirmation.',
                    'data' => [
                        'requestId' => $payload['requestId'] ?? 'PAY-X',
                        'transactionId' => 'E110526AC14K7X2M9',
                        'amount' => $payload['amount'] ?? 5000,
                        'currency' => 'TZS',
                        'status' => 'PENDING',
                        'description' => 'Push queued for dispatch',
                    ],
                ], 202);
            }

            return Http::response(['unexpected' => $request->url()], 500);
        });
    }
}
