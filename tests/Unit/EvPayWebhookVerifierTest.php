<?php

namespace Tests\Unit;

use App\Services\EvPay\EvPayException;
use App\Services\EvPay\EvPayWebhookVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

class EvPayWebhookVerifierTest extends TestCase
{
    public function test_valid_signature_is_accepted(): void
    {
        $t = time();
        $body = $this->payload('payment.failed', $t);
        $verified = app(EvPayWebhookVerifier::class)->verify(
            $this->request($body, $t, 'payment.failed', 'del-ok')
        );

        $this->assertSame('payment.failed', $verified['event']);
        $this->assertSame('del-ok', $verified['delivery_id']);
        $this->assertSame($t, $verified['timestamp']);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        $t = time();
        $body = $this->payload('payment.succeeded', $t);
        $request = $this->request($body, $t, 'payment.succeeded', 'del-invalid');
        $request->headers->set('X-EvPay-Signature', "t={$t},v1=".str_repeat('a', 64));

        app(EvPayWebhookVerifier::class)->verify($request);
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Missing webhook signature.');

        $t = time();
        $body = $this->payload('payment.succeeded', $t);
        $request = Request::create('/api/evpay/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => 'payment.succeeded',
            'HTTP_X_EVPAY_DELIVERY' => 'del-missing',
        ], $body);

        app(EvPayWebhookVerifier::class)->verify($request);
    }

    public function test_timestamp_older_than_five_minutes_is_rejected(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Webhook timestamp is outside the allowed tolerance.');

        $t = time() - 301;
        $body = $this->payload('payment.succeeded', $t);
        app(EvPayWebhookVerifier::class)->verify(
            $this->request($body, $t, 'payment.succeeded', 'del-old')
        );
    }

    public function test_future_timestamp_outside_tolerance_is_rejected(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Webhook timestamp is outside the allowed tolerance.');

        $t = time() + 301;
        $body = $this->payload('payment.succeeded', $t);
        app(EvPayWebhookVerifier::class)->verify(
            $this->request($body, $t, 'payment.succeeded', 'del-future')
        );
    }

    public function test_body_timestamp_must_match_signature_timestamp(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Webhook timestamp does not match the signature timestamp.');

        $t = time();
        $body = $this->payload('payment.succeeded', $t - 1);
        app(EvPayWebhookVerifier::class)->verify(
            $this->request($body, $t, 'payment.succeeded', 'del-ts')
        );
    }

    public function test_header_event_must_match_body_event(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Webhook event header does not match the payload event.');

        $t = time();
        $body = $this->payload('payment.succeeded', $t);
        $request = $this->request($body, $t, 'payment.failed', 'del-event');
        app(EvPayWebhookVerifier::class)->verify($request);
    }

    public function test_tampered_raw_body_fails_verification(): void
    {
        $this->expectException(EvPayException::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        $t = time();
        $original = $this->payload('payment.succeeded', $t);
        $tampered = $this->payload('payment.succeeded', $t, ['amount' => 999999]);
        $request = $this->request($tampered, $t, 'payment.succeeded', 'del-tamper');
        $request->headers->set('X-EvPay-Signature', $this->signature($t, $original));

        app(EvPayWebhookVerifier::class)->verify($request);
    }

    /**
     * @param  array<string, mixed>  $dataOverrides
     */
    private function payload(string $event, int $timestamp, array $dataOverrides = []): string
    {
        return json_encode([
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => array_merge([
                'id' => 'E110526AC14K7X2M9',
                'orderReference' => 'PAY-TEST',
                'status' => 'SUCCESS',
            ], $dataOverrides),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function signature(int $timestamp, string $rawBody): string
    {
        $v1 = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'test-webhook-secret');

        return "t={$timestamp},v1={$v1}";
    }

    private function request(string $body, int $timestamp, string $headerEvent, string $deliveryId): Request
    {
        return Request::create('/api/evpay/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_EVPAY_EVENT' => $headerEvent,
            'HTTP_X_EVPAY_DELIVERY' => $deliveryId,
            'HTTP_X_EVPAY_SIGNATURE' => $this->signature($timestamp, $body),
        ], $body);
    }
}
