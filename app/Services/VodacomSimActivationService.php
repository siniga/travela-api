<?php

namespace App\Services;

use App\Models\Esim;
use Illuminate\Support\Facades\Log;

class VodacomSimActivationService
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    public function isActivated(Esim $esim): bool
    {
        return $esim->vodacom_activated_at !== null
            && $esim->activation_status === Esim::ACTIVATION_STATUS_SUCCESS;
    }

    /**
     * Activate a SIM on Vodacom. Idempotent when already activated.
     *
     * @throws \RuntimeException
     */
    public function activate(Esim $esim): Esim
    {
        $esim->refresh();

        if ($this->isActivated($esim)) {
            return $esim;
        }

        $query = $this->buildActivateQuery($esim);

        Log::info('Vodacom SIM activation request', [
            'esim_id' => $esim->id,
            'msisdn' => $query['msisdn'] ?? null,
            'iccid' => $query['iccid'] ?? null,
            'imsi' => $query['imsi'] ?? null,
        ]);

        try {
            $response = $this->vodacom->post('/api/sims-activate', $query);
            $body = $response->json();
            $responseBody = is_array($body) ? $body : ['raw' => (string) $response->body()];
            $httpStatus = $response->status();

            if ($this->isActivationSuccess($responseBody, $httpStatus)) {
                return $this->markActivated($esim, $responseBody);
            }

            $message = $this->failureMessage($httpStatus, $responseBody);
            $this->markFailed($esim, $message, $responseBody);

            throw new \RuntimeException($message);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->markFailed($esim, $e->getMessage(), null);
            Log::error('Vodacom SIM activation request exception', [
                'esim_id' => $esim->id,
                'msisdn' => $esim->msisdn,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Vodacom SIM activation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    public function buildActivateQuery(Esim $esim): array
    {
        $query = [];

        if ($esim->msisdn) {
            $query['msisdn'] = '+'.Esim::normalizeMsisdn($esim->msisdn);
        }

        if ($esim->iccid) {
            $query['iccid'] = strtoupper(trim($esim->iccid));
        }

        if ($esim->imsi) {
            $query['imsi'] = trim($esim->imsi);
        }

        if ($query === []) {
            throw new \RuntimeException('Cannot activate SIM: msisdn, iccid, or imsi is required.');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function isActivationSuccess(array $responseBody, int $httpStatus): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return false;
        }

        $status = strtoupper((string) ($responseBody['status'] ?? $responseBody['Status'] ?? ''));

        if (in_array($status, ['SUCCESS', 'ACTIVE', 'ACTIVATED'], true)) {
            return true;
        }

        if (in_array(strtolower($status), ['success', 'successful', 'active', 'activated', 'completed', 'complete'], true)) {
            return true;
        }

        if (filter_var($responseBody['success'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $message = strtolower((string) ($responseBody['message'] ?? $responseBody['Message'] ?? ''));
        if (str_contains($message, 'already active') || str_contains($message, 'already activated')) {
            return true;
        }

        return $httpStatus >= 200 && $httpStatus < 300 && $status === '';
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function failureMessage(int $httpStatus, array $responseBody): string
    {
        $detail = $responseBody['error']
            ?? $responseBody['message']
            ?? $responseBody['Message']
            ?? null;

        $message = "Vodacom SIM activation failed (HTTP {$httpStatus})";
        if (is_string($detail) && $detail !== '') {
            $message .= ': '.$detail;
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function markActivated(Esim $esim, array $responseBody): Esim
    {
        $esim->update([
            'activation_status' => Esim::ACTIVATION_STATUS_SUCCESS,
            'activation_error' => null,
            'vodacom_activation_response' => $responseBody,
            'vodacom_activated_at' => now(),
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
        ]);

        Log::info('Vodacom SIM activated', [
            'esim_id' => $esim->id,
            'msisdn' => $esim->msisdn,
        ]);

        return $esim->fresh();
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function markFailed(Esim $esim, string $error, ?array $responseBody): void
    {
        $esim->update([
            'activation_status' => Esim::ACTIVATION_STATUS_FAILED,
            'activation_error' => $error,
            'vodacom_activation_response' => $responseBody,
            'provider_status' => Esim::PROVIDER_STATUS_SUSPENDED,
        ]);

        Log::warning('Vodacom SIM activation failed', [
            'esim_id' => $esim->id,
            'msisdn' => $esim->msisdn,
            'error' => $error,
        ]);
    }
}
