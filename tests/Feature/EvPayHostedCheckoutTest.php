<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\EvPayService;
use App\Services\SimAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvPayHostedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_checkout_url_uses_hosted_callback_not_ussd_webhook(): void
    {
        $order = $this->makePendingOrder();

        $result = app(EvPayService::class)->createCheckoutUrl($order);

        $this->assertNotEmpty($result['checkout_url']);
        $this->assertStringStartsWith('https://checkout.evmak.com/checkout/test-merchant?', $result['checkout_url']);
        $this->assertStringContainsString('data=', $result['checkout_url']);
        $this->assertStringContainsString('sig=', $result['checkout_url']);
        $this->assertSame('https://api.test/api/payments/evpay/callback', $result['payload']['callbackUrl']);
        $this->assertStringStartsWith('ORD-', $result['payment_reference']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_hosted_callback_marks_order_paid_once(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once();
        });

        $order = $this->makePendingOrder();
        app(EvPayService::class)->prepare($order);

        $payload = [
            'status' => 'success',
            'reference' => $order->payment_reference,
            'payment_id' => 'gw-123',
        ];
        $data = base64_encode(json_encode($payload));
        $sig = hash_hmac('sha256', $data, 'test-hosted-secret');

        $this->postJson('/api/payments/evpay/callback', [
            'data' => $data,
            'sig' => $sig,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_status', 'paid');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->status);
        $this->assertSame('gw-123', $order->gateway_payment_id);
    }

    public function test_mobile_money_webhook_route_is_unchanged(): void
    {
        $t = time();
        $rawBody = json_encode([
            'event' => 'payment.failed',
            'timestamp' => $t,
            'data' => [
                'id' => 'TX-1',
                'orderReference' => 'PAY-UNKNOWN',
                'status' => 'FAILED',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $v1 = hash_hmac('sha256', $t.'.'.$rawBody, 'test-webhook-secret');

        $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.failed',
            'HTTP_X_EVPAY_DELIVERY' => 'del-hosted-coexist',
            'HTTP_X_EVPAY_SIGNATURE' => "t={$t},v1={$v1}",
        ], $rawBody)->assertOk()->assertJson(['status' => 'OK']);
    }

    private function makePendingOrder(): Order
    {
        $user = User::factory()->create();

        return Order::create([
            'draft_id' => 'DRAFT-HOSTED-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal' => 5000,
            'discount_amount' => 0,
            'total_amount' => 5000,
            'currency' => 'TZS',
        ]);
    }
}
