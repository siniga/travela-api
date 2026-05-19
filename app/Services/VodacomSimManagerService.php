<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VodacomSimManagerService
{
    private function client()
    {
        $baseUrl = rtrim((string) config('services.vodacom_sim.base_url'), '/');
        $apiKey = (string) config('services.vodacom_sim.api_key');

        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException(
                'Vodacom SIM Manager is not configured: set VODACOM_SIM_BASE_URL to the full API origin '
                .'(e.g. https://simmanager.vodacom.co.tz) in .env. Do not use a path like "api" or a Docker service name.'
            );
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }

    public function get(string $path, array $query = []): Response
    {
        $response = $this->client()->get($path, $query);

        if (! $response->successful()) {
            $this->logFailure('GET', $path, $query, null, $response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $jsonBody
     * @param  array<string, mixed>|null  $logContext  order_id, user_id, user_esim_id, etc. (no secrets)
     */
    public function post(string $path, array $query = [], array $jsonBody = [], ?array $logContext = null): Response
    {
        $pending = $this->client();

        if (! empty($query)) {
            $pending = $pending->withQueryParameters($query);
        }

        $response = empty($jsonBody)
            ? $pending->post($path)
            : $pending->post($path, $jsonBody);

        if (! $response->successful()) {
            $this->logFailure('POST', $path, $query, $jsonBody, $response, $logContext);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $jsonBody
     * @param  array<string, mixed>|null  $logContext
     */
    private function logFailure(
        string $method,
        string $path,
        array $query,
        ?array $jsonBody,
        Response $response,
        ?array $logContext = null,
    ): void {
        if ($path === '/api/recharge') {
            Log::warning('Vodacom recharge request failed', array_merge(
                $this->rechargeLogFields($logContext, $jsonBody ?? []),
                [
                    'status' => $response->status(),
                    'response_body' => mb_substr((string) $response->body(), 0, 8000),
                ]
            ));

            return;
        }

        Log::warning('Vodacom SIM Manager request failed', [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'request_payload' => $jsonBody,
            'status' => $response->status(),
            'response_body' => mb_substr((string) $response->body(), 0, 8000),
        ]);
    }

    /**
     * Flat, safe fields for recharge logs (values visible in laravel.log, not key names only).
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function rechargeLogFields(?array $context, array $payload): array
    {
        $context = $context ?? [];

        return [
            'order_id' => $context['order_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'user_esim_id' => $context['user_esim_id'] ?? null,
            'msisdn' => $payload['msisdn'] ?? $context['msisdn'] ?? null,
            'network_id' => $payload['network_id'] ?? $context['network_id'] ?? null,
            'product_id' => $payload['product_id'] ?? $context['product_id'] ?? null,
            'reference' => $payload['reference'] ?? $context['reference'] ?? null,
            'airtime_amount' => $payload['airtime_amount'] ?? $context['airtime_amount'] ?? null,
        ];
    }
}
