<?php

namespace App\Services;

use App\Models\Esim;
use Illuminate\Http\Client\Response;

class VodacomSimProvisioningService
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    /**
     * Register SIM on Vodacom (POST /api/sims) then activate (POST /api/sims-activate).
     *
     * @return array{create: array<string, mixed>|null, activate: array<string, mixed>|null}
     */
    public function provision(Esim $esim): array
    {
        $createResponse = $this->createOnVodacom($esim);
        $activateResponse = $this->activateOnVodacom($esim);

        return [
            'create' => $createResponse,
            'activate' => $activateResponse,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createOnVodacom(Esim $esim): array
    {
        $payload = $this->buildCreatePayload($esim);
        $response = $this->vodacom->post('/api/sims', [], $payload);

        if ($response->successful()) {
            return $this->decodeResponse($response);
        }

        if ($response->status() === 422 && $this->simAlreadyExists($response)) {
            return ['already_exists' => true, 'message' => 'SIM already registered on Vodacom.'];
        }

        throw new \RuntimeException($this->errorMessage($response, 'Vodacom SIM create failed'));
    }

    /**
     * @return array<string, mixed>
     */
    public function activateOnVodacom(Esim $esim): array
    {
        $query = $this->buildIdentifierQuery($esim);

        if ($query === []) {
            throw new \RuntimeException('MSISDN or ICCID is required to activate on Vodacom.');
        }

        $response = $this->vodacom->post('/api/sims-activate', $query);

        if (! $response->successful()) {
            throw new \RuntimeException($this->errorMessage($response, 'Vodacom SIM activation failed'));
        }

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCreatePayload(Esim $esim): array
    {
        $networkId = (int) ($esim->network_id ?: config('services.vodacom_sim.default_network_id', 1));
        $description = trim((string) ($esim->description ?? ''));
        $description = $description !== '' ? $description : 'Travela import';

        if (is_string($esim->iccid) && trim($esim->iccid) !== '') {
            return [
                'iccid' => strtoupper(trim($esim->iccid)),
                'network_id' => $networkId,
                'description' => $description,
            ];
        }

        if (is_string($esim->imsi) && trim($esim->imsi) !== '') {
            return [
                'imsi' => trim($esim->imsi),
                'network_id' => $networkId,
                'description' => $description,
            ];
        }

        return [
            'msisdn' => Esim::toVodacomMsisdn((string) $esim->msisdn),
            'network_id' => $networkId,
            'description' => $description,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildIdentifierQuery(Esim $esim): array
    {
        $query = [];

        if (is_string($esim->msisdn) && trim($esim->msisdn) !== '') {
            $query['msisdn'] = Esim::toVodacomMsisdn($esim->msisdn);
        }

        if (is_string($esim->iccid) && trim($esim->iccid) !== '') {
            $query['iccid'] = strtoupper(trim($esim->iccid));
        }

        if (is_string($esim->imsi) && trim($esim->imsi) !== '') {
            $query['imsi'] = trim($esim->imsi);
        }

        return $query;
    }

    private function simAlreadyExists(Response $response): bool
    {
        $body = strtolower((string) $response->body());

        return str_contains($body, 'already')
            || str_contains($body, 'exists')
            || str_contains($body, 'duplicate');
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $json = $response->json();

        if (is_array($json)) {
            if (isset($json['error']) && is_string($json['error'])) {
                return $json['error'];
            }

            if (isset($json['message']) && is_string($json['message'])) {
                return $json['message'];
            }

            if (isset($json['errors']) && is_array($json['errors'])) {
                $first = $json['errors'][0] ?? null;
                if (is_array($first) && isset($first['detail']) && is_string($first['detail'])) {
                    return $first['detail'];
                }
            }
        }

        $body = trim((string) $response->body());

        return $body !== '' ? mb_substr($body, 0, 500) : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['raw' => (string) $response->body()];
    }
}
