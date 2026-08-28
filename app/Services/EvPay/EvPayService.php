<?php

namespace App\Services\EvPay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EvPayService
{
    public const TOKEN_CACHE_KEY = 'evpay.access_token';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const OPERATORS = ['TigoPesa', 'AirtelMoney', 'HaloPesa', 'Mpesa'];

    public function authenticate(): array
    {
        $clientId = trim((string) config('services.evpay.client_id'));
        $clientSecret = trim((string) config('services.evpay.client_secret'));

        if ($clientId === '' || $clientSecret === '') {
            throw new EvPayException('EVPay credentials are not configured.', 502, 'evpay_not_configured');
        }

        $payload = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ];
        $rawBody = $this->encodeJson($payload);

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(30)
            ->acceptJson()
            ->withBody($rawBody, 'application/json')
            ->post('/api/auth/v1');

        if (! $response->successful()) {
            $this->logFailure('POST', '/api/auth/v1', $response);

            throw new EvPayException(
                'Unable to authenticate with the payment provider.',
                502,
                'authentication_required',
            );
        }

        $data = $response->json();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new EvPayException('Payment provider authentication returned an invalid token.', 502, 'authentication_required');
        }

        return [
            'access_token' => $token,
            'token_type' => is_array($data) ? ($data['token_type'] ?? 'Bearer') : 'Bearer',
            'expires_in' => is_array($data) ? (int) ($data['expires_in'] ?? 300) : 300,
        ];
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $auth = $this->authenticate();
        $ttl = max(1, ((int) $auth['expires_in']) - 30);
        Cache::put(self::TOKEN_CACHE_KEY, $auth['access_token'], $ttl);

        return $auth['access_token'];
    }

    public function forgetAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /**
     * HMAC-SHA256 hex digest of "{timestamp}.{rawBody}".
     * $timestamp is Unix time in milliseconds for outbound EVPay API requests.
     */
    public function generateSignature(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->sigKey());
    }

    public function currentTimestampMs(): string
    {
        return (string) (int) floor(microtime(true) * 1000);
    }

    public function encodeJson(array $payload): string
    {
        $json = json_encode($payload, self::JSON_FLAGS);
        if ($json === false) {
            throw new EvPayException('Failed to encode EVPay payload.', 500, 'evpay_error');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createMobileMoneyPayment(array $payload, string $idempotencyKey, ?string $rawBody = null): array
    {
        $operator = $payload['fsOperator'] ?? null;
        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            throw new EvPayException('Unsupported mobile-money operator.', 422, 'validation_error');
        }

        $body = $rawBody ?? $this->encodeJson($payload);

        $response = $this->signedRequest('POST', '/api/v1/payment/ussd', $body, [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            $response = $this->signedRequest('POST', '/api/v1/payment/ussd', $body, [
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        if (! in_array($response->status(), [200, 202], true)) {
            throw $this->exceptionFromResponse($response, 'POST', '/api/v1/payment/ussd', $payload['requestId'] ?? $idempotencyKey);
        }

        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentStatus(string $id): array
    {
        $path = '/api/v1/payment/'.rawurlencode($id);
        $response = $this->signedRequest('GET', $path, '');

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            $response = $this->signedRequest('GET', $path, '');
        }

        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response, 'GET', $path, $id);
        }

        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    private function signedRequest(string $method, string $path, string $rawBody, array $extraHeaders = []): Response
    {
        $timestamp = $this->currentTimestampMs();
        $headers = array_merge([
            'Authorization' => 'Bearer '.$this->getAccessToken(),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->generateSignature($timestamp, $rawBody),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $extraHeaders);

        $pending = Http::baseUrl($this->baseUrl())
            ->timeout(30)
            ->withHeaders($headers);

        $response = strtoupper($method) === 'GET'
            ? $pending->get($path)
            : $pending->withBody($rawBody, 'application/json')->post($path);

        if (! $response->successful() && ! in_array($response->status(), [202, 401], true)) {
            $this->logFailure($method, $path, $response, $extraHeaders['Idempotency-Key'] ?? null);
        }

        return $response;
    }

    private function exceptionFromResponse(Response $response, string $method, string $path, mixed $requestId): EvPayException
    {
        $status = $response->status();
        $json = $response->json();
        $providerCode = is_array($json)
            ? (string) ($json['code'] ?? $json['error'] ?? $json['error_code'] ?? '')
            : '';
        $providerMessage = is_array($json)
            ? (string) ($json['message'] ?? $json['error_description'] ?? '')
            : '';

        $this->logFailure($method, $path, $response, is_string($requestId) ? $requestId : null);

        [$httpStatus, $code, $message] = match (true) {
            $status === 401 => [502, 'authentication_required', 'Payment provider authentication failed.'],
            $status === 403 => [502, 'permission_denied', 'Payment provider denied the request.'],
            $status === 404 => [404, 'not_found', 'Payment was not found.'],
            $status === 409 => [409, 'idempotency_conflict', 'This payment request conflicts with an existing transaction.'],
            $status === 422 => [422, 'validation_error', $providerMessage !== '' ? $providerMessage : 'The payment request was rejected.'],
            $status === 402 => [402, 'insufficient_funds', 'Insufficient funds to complete this payment.'],
            $status >= 500 => [502, 'upstream_error', 'Payment provider is temporarily unavailable.'],
            default => [502, $providerCode !== '' ? $providerCode : 'evpay_error', 'Unable to complete the payment request.'],
        };

        return new EvPayException(
            $message,
            $httpStatus,
            $code,
            is_string($requestId) ? $requestId : null,
        );
    }

    private function logFailure(string $method, string $path, Response $response, ?string $requestId = null): void
    {
        $json = $response->json();

        Log::warning('EVPay request failed', [
            'method' => $method,
            'path' => $path,
            'request_id' => $requestId,
            'http_status' => $response->status(),
            'evpay_code' => is_array($json) ? ($json['code'] ?? $json['error'] ?? null) : null,
            'evpay_message' => is_array($json) ? ($json['message'] ?? null) : null,
            'evpay_request_id' => is_array($json)
                ? ($json['requestId'] ?? $json['request_id'] ?? data_get($json, 'data.requestId'))
                : null,
        ]);
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('services.evpay.base_url'), '/');
        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            throw new EvPayException('EVPay base URL is not configured.', 502, 'evpay_not_configured');
        }

        return $base;
    }

    private function sigKey(): string
    {
        $key = trim((string) config('services.evpay.sig_key'));
        if ($key === '') {
            throw new EvPayException('EVPay signing key is not configured.', 502, 'evpay_not_configured');
        }

        return $key;
    }
}
