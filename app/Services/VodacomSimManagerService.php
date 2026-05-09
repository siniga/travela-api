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

    public function post(string $path, array $query = [], array $jsonBody = []): Response
    {
        $pending = $this->client();

        if (! empty($query)) {
            $pending = $pending->withQueryParameters($query);
        }

        $response = empty($jsonBody)
            ? $pending->post($path)
            : $pending->post($path, $jsonBody);

        if (! $response->successful()) {
            $this->logFailure('POST', $path, $query, $jsonBody, $response);
        }

        return $response;
    }

    private function logFailure(string $method, string $path, array $query, ?array $jsonBody, Response $response): void
    {
        Log::warning('Vodacom SIM Manager request failed', [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'body' => $jsonBody ? array_keys($jsonBody) : null,
            'status' => $response->status(),
            'response' => mb_substr((string) $response->body(), 0, 2000),
        ]);
    }
}

