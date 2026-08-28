<?php

namespace App\Services\EvPay;

use Illuminate\Http\Request;

class EvPayWebhookVerifier
{
    public const TOLERANCE_SECONDS = 300;

    /**
     * @return array{
     *     payload: array<string, mixed>,
     *     event: string,
     *     delivery_id: string,
     *     timestamp: int
     * }
     */
    public function verify(Request $request): array
    {
        $secret = trim((string) config('services.evpay.webhook_secret'));
        if ($secret === '') {
            throw new EvPayException(
                'EVPay webhook secret is not configured.',
                500,
                'webhook_not_configured',
            );
        }

        $signatureHeader = $request->header('X-EvPay-Signature');
        if (! is_string($signatureHeader) || $signatureHeader === '') {
            throw new EvPayException('Missing webhook signature.', 401, 'missing_signature');
        }

        [$timestamp, $provided] = $this->parseSignatureHeader($signatureHeader);

        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new EvPayException('Webhook timestamp is outside the allowed tolerance.', 401, 'timestamp_expired');
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        if (! hash_equals($expected, strtolower($provided))) {
            throw new EvPayException('Invalid webhook signature.', 401, 'invalid_signature');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            throw new EvPayException('Invalid webhook JSON payload.', 400, 'invalid_payload');
        }

        $bodyTimestamp = $payload['timestamp'] ?? null;
        if (! is_numeric($bodyTimestamp) || (int) $bodyTimestamp !== $timestamp) {
            throw new EvPayException('Webhook timestamp does not match the signature timestamp.', 401, 'timestamp_mismatch');
        }

        $headerEvent = $request->header('X-EvPay-Event');
        $bodyEvent = $payload['event'] ?? null;
        if (! is_string($headerEvent) || $headerEvent === '' || $headerEvent !== $bodyEvent) {
            throw new EvPayException('Webhook event header does not match the payload event.', 401, 'event_mismatch');
        }

        $deliveryId = $request->header('X-EvPay-Delivery');
        if (! is_string($deliveryId) || trim($deliveryId) === '') {
            throw new EvPayException('Missing webhook delivery ID.', 400, 'missing_delivery_id');
        }

        return [
            'payload' => $payload,
            'event' => $bodyEvent,
            'delivery_id' => trim($deliveryId),
            'timestamp' => $timestamp,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $version = null;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === 't') {
                $timestamp = $value;
            }

            if ($key === 'v1') {
                $version = $value;
            }
        }

        if ($timestamp === null || $version === null || $timestamp === '' || $version === '') {
            throw new EvPayException('Malformed webhook signature header.', 400, 'malformed_signature');
        }

        if (! ctype_digit($timestamp)) {
            throw new EvPayException('Malformed webhook signature timestamp.', 400, 'malformed_signature');
        }

        if (! ctype_xdigit($version)) {
            throw new EvPayException('Malformed webhook signature digest.', 400, 'malformed_signature');
        }

        return [(int) $timestamp, strtolower($version)];
    }
}
