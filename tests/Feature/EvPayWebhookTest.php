<?php

namespace Tests\Feature;

use App\Models\EvPayWebhookDelivery;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\SimAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signature_is_accepted(): void
    {
        $this->withoutFulfillment();
        [$payment] = $this->makePendingPayment();
        $t = time();

        $this->postWebhook($this->paymentEvent('payment.failed', 'FAILED', $payment, $t), $t)
            ->assertOk()
            ->assertJson(['status' => 'OK']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $t = time();
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);

        $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
            'HTTP_X_EVPAY_DELIVERY' => 'del-invalid',
            'HTTP_X_EVPAY_SIGNATURE' => "t={$t},v1=".str_repeat('a', 64),
        ], $body)->assertStatus(401)
            ->assertJsonPath('code', 'invalid_signature');
    }

    public function test_missing_signature_is_rejected(): void
    {
        $t = time();
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);

        $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
            'HTTP_X_EVPAY_DELIVERY' => 'del-missing',
        ], $body)->assertStatus(401)
            ->assertJsonPath('code', 'missing_signature');
    }

    public function test_timestamp_older_than_five_minutes_is_rejected(): void
    {
        $t = time() - 301;
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);

        $this->postWebhook($body, $t, 'del-old')->assertStatus(401)
            ->assertJsonPath('code', 'timestamp_expired');
    }

    public function test_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $t = time() + 301;
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);

        $this->postWebhook($body, $t, 'del-future')->assertStatus(401)
            ->assertJsonPath('code', 'timestamp_expired');
    }

    public function test_body_timestamp_must_match_signature_timestamp(): void
    {
        $t = time();
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t - 1);

        $this->postWebhook($body, $t, 'del-ts-mismatch')->assertStatus(401)
            ->assertJsonPath('code', 'timestamp_mismatch');
    }

    public function test_header_event_must_match_body_event(): void
    {
        $t = time();
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);

        $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.failed',
            'HTTP_X_EVPAY_DELIVERY' => 'del-event-mismatch',
            'HTTP_X_EVPAY_SIGNATURE' => $this->signatureHeader($t, $body),
        ], $body)->assertStatus(401)
            ->assertJsonPath('code', 'event_mismatch');
    }

    public function test_tampered_raw_body_fails_verification(): void
    {
        $t = time();
        $original = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t);
        $tampered = $this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t, [
            'amount' => 999999,
        ]);

        $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
            'HTTP_X_EVPAY_DELIVERY' => 'del-tamper',
            'HTTP_X_EVPAY_SIGNATURE' => $this->signatureHeader($t, $original),
        ], $tampered)->assertStatus(401)
            ->assertJsonPath('code', 'invalid_signature');
    }

    public function test_payment_succeeded_fulfills_once(): void
    {
        [$payment, $order] = $this->makePendingPayment();
        $t = time();

        $this->mock(SimAssignmentService::class, function ($mock) use ($order) {
            $mock->shouldReceive('fulfillPaidOrder')
                ->once()
                ->with(\Mockery::on(fn ($o) => (int) $o->id === (int) $order->id), \Mockery::type('array'))
                ->andReturn(['assigned' => true, 'reason' => 'assigned']);
        });

        $this->postWebhook(
            $this->paymentEvent('payment.succeeded', 'SUCCESS', $payment, $t),
            $t,
            'del-success',
        )->assertOk();

        $payment->refresh();
        $order->refresh();
        $this->assertSame('SUCCESS', $payment->status);
        $this->assertNotNull($payment->fulfilled_at);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('E110526AC14K7X2M9', $payment->transaction_id);
        $this->assertSame('FSP-1', $payment->fsp_reference);
        $this->assertSame('CBS-1', $payment->cbs_reference);
    }

    public function test_payment_settled_marks_reconciled_without_second_fulfillment(): void
    {
        [$payment, $order] = $this->makePendingPayment();
        $t = time();

        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once()->andReturn(['assigned' => true, 'reason' => 'assigned']);
        });

        $this->postWebhook($this->paymentEvent('payment.succeeded', 'SUCCESS', $payment, $t), $t, 'del-s1')->assertOk();

        $payment->refresh();
        $fulfilledAt = $payment->fulfilled_at;

        $t2 = time();
        $this->postWebhook($this->paymentEvent('payment.settled', 'SETTLED', $payment, $t2), $t2, 'del-s2')->assertOk();

        $payment->refresh();
        $order->refresh();
        $this->assertSame('SETTLED', $payment->status);
        $this->assertNotNull($payment->settled_at);
        $this->assertEquals($fulfilledAt?->timestamp, $payment->fulfilled_at?->timestamp);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_payment_failed_is_persisted(): void
    {
        $this->withoutFulfillment();
        [$payment, $order] = $this->makePendingPayment();
        $t = time();

        $this->postWebhook($this->paymentEvent('payment.failed', 'FAILED', $payment, $t), $t, 'del-fail')->assertOk();

        $payment->refresh();
        $order->refresh();
        $this->assertSame('FAILED', $payment->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertNull($payment->fulfilled_at);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'FAILED']);
    }

    public function test_payment_on_hold_is_persisted(): void
    {
        $this->withoutFulfillment();
        [$payment] = $this->makePendingPayment();
        $t = time();

        $this->postWebhook($this->paymentEvent('payment.on_hold', 'ON-HOLD', $payment, $t), $t, 'del-hold')->assertOk();

        $this->assertSame('ON-HOLD', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->fulfilled_at);
    }

    public function test_payment_refunded_and_reversed_remain_visible(): void
    {
        $this->withoutFulfillment();
        [$payment] = $this->makePendingPayment();

        $t = time();
        $this->postWebhook($this->paymentEvent('payment.refunded', 'REFUNDED', $payment, $t), $t, 'del-refund')->assertOk();
        $this->assertSame('REFUNDED', $payment->fresh()->status);

        $payment2 = $payment->replicate();
        $payment2->request_id = 'PAY-REV-1';
        $payment2->status = 'SUCCESS';
        $payment2->save();

        $t2 = time();
        $this->postWebhook($this->paymentEvent('payment.reversed', 'REVERSED', $payment2, $t2), $t2, 'del-rev')->assertOk();
        $this->assertSame('REVERSED', $payment2->fresh()->status);
        $this->assertSame(2, Payment::count());
    }

    public function test_duplicate_delivery_id_does_not_reprocess(): void
    {
        [$payment] = $this->makePendingPayment();
        $t = time();
        $body = $this->paymentEvent('payment.succeeded', 'SUCCESS', $payment, $t);

        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once()->andReturn(['assigned' => true, 'reason' => 'assigned']);
        });

        $this->postWebhook($body, $t, 'del-dup')->assertOk();
        $this->postWebhook($body, $t, 'del-dup')->assertOk()->assertJson(['status' => 'OK']);

        $this->assertSame(1, EvPayWebhookDelivery::count());
        $this->assertSame(1, Payment::whereNotNull('fulfilled_at')->count());
    }

    public function test_duplicate_success_event_does_not_duplicate_fulfillment(): void
    {
        [$payment] = $this->makePendingPayment();

        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->once()->andReturn(['assigned' => true, 'reason' => 'assigned']);
        });

        $t = time();
        $this->postWebhook($this->paymentEvent('payment.succeeded', 'SUCCESS', $payment, $t), $t, 'del-a')->assertOk();
        $this->postWebhook($this->paymentEvent('payment.succeeded', 'SUCCESS', $payment, $t), $t, 'del-b')->assertOk();

        $this->assertNotNull($payment->fresh()->fulfilled_at);
    }

    public function test_unknown_payment_reference_is_acknowledged_without_fulfillment(): void
    {
        $this->withoutFulfillment();
        $t = time();

        $this->postWebhook($this->paymentEvent('payment.succeeded', 'SUCCESS', null, $t, [
            'orderReference' => 'PAY-DOES-NOT-EXIST',
            'id' => 'TX-UNKNOWN',
        ]), $t, 'del-unknown')->assertOk()->assertJson(['status' => 'OK']);

        $this->assertSame(0, Payment::count());
        $this->assertDatabaseHas('evpay_webhook_deliveries', [
            'delivery_id' => 'del-unknown',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function paymentEvent(string $event, string $status, ?Payment $payment, int $timestamp, array $overrides = []): string
    {
        $payload = [
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => array_merge([
                'id' => 'E110526AC14K7X2M9',
                'orderReference' => $payment?->request_id ?? 'PAY-UNKNOWN',
                'fspReference' => 'FSP-1',
                'cbsReference' => 'CBS-1',
                'status' => $status,
                'amount' => 5000,
                'currency' => 'TZS',
                'description' => $status,
            ], $overrides),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function signatureHeader(int $timestamp, string $rawBody): string
    {
        $v1 = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-webhook-secret');

        return "t={$timestamp},v1={$v1}";
    }

    private function postWebhook(string $rawBody, int $timestamp, string $deliveryId = 'del-1')
    {
        $event = json_decode($rawBody, true)['event'];

        return $this->call('POST', '/api/evpay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'EvPay-Webhook/1.0',
            'HTTP_X_EVPAY_EVENT' => $event,
            'HTTP_X_EVPAY_DELIVERY' => $deliveryId,
            'HTTP_X_EVPAY_SIGNATURE' => $this->signatureHeader($timestamp, $rawBody),
        ], $rawBody);
    }

    /**
     * @return array{0: Payment, 1: Order}
     */
    private function makePendingPayment(): array
    {
        $user = User::factory()->create();
        $order = Order::create([
            'draft_id' => 'DRAFT-WH-'.uniqid(),
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'subtotal' => 5000,
            'discount_amount' => 0,
            'total_amount' => 5000,
            'currency' => 'TZS',
        ]);

        $payment = Payment::create([
            'request_id' => 'PAY-'.strtoupper(uniqid()),
            'user_id' => $user->id,
            'order_id' => $order->id,
            'provider' => 'evpay',
            'payment_method' => 'mobile_money',
            'operator' => 'Mpesa',
            'phone_number' => '255712345678',
            'amount' => 5000,
            'currency' => 'TZS',
            'status' => 'PENDING',
        ]);

        return [$payment, $order];
    }

    private function withoutFulfillment(): void
    {
        $this->mock(SimAssignmentService::class, function ($mock) {
            $mock->shouldReceive('fulfillPaidOrder')->never();
        });
    }
}
