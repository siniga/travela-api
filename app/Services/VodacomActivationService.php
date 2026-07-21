<?php

namespace App\Services;

use App\Models\Esim;
use Illuminate\Support\Facades\Log;

class VodacomActivationService
{
    public function __construct(
        private readonly VodacomSimManagerService $vodacom,
    ) {
    }

    /**
     * Activate a SIM on Vodacom when it has not been activated yet.
     *
     * @return array{
     *     success: bool,
     *     skipped: bool,
     *     reason?: string,
     *     error?: string,
     *     http_status?: int,
     *     response?: array<string, mixed>|null
     * }
     */
    public function activateIfNeeded(Esim $esim): array
    {
        $esim->refresh();

        if ($esim->vodacom_activated_at !== null) {
            return [
                'success' => true,
                'skipped' => true,
                'reason' => 'already_activated',
            ];
        }

        $query = $this->buildActivateQuery($esim);
        if ($query === []) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => 'SIM is missing msisdn, iccid, and imsi required for Vodacom activation.',
            ];
        }

        $logContext = [
            'esim_id' => $esim->id,
            'msisdn' => $query['msisdn'] ?? null,
            'iccid' => $query['iccid'] ?? null,
            'imsi' => $query['imsi'] ?? null,
        ];

        Log::info('Vodacom SIM activate request', $logContext);

        try {
            $response = $this->vodacom->post('/api/sims-activate', $query);
            $body = $response->json();
            $responseBody = is_array($body) ? $body : ['raw' => (string) $response->body()];
            $httpStatus = $response->status();

            if ($this->isActivationSuccess($responseBody, $httpStatus)) {
                $esim->forceFill([
                    'vodacom_activated_at' => now(),
                    'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
                ])->save();

                Log::info('Vodacom SIM activated', array_merge($logContext, [
                    'http_status' => $httpStatus,
                    'response_body' => $responseBody,
                ]));

                return [
                    'success' => true,
                    'skipped' => false,
                    'reason' => 'activated',
                    'http_status' => $httpStatus,
                    'response' => $responseBody,
                ];
            }

            $error = $this->extractErrorMessage($responseBody, $httpStatus);

            Log::warning('Vodacom SIM activation failed', array_merge($logContext, [
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
                'error' => $error,
            ]));

            return [
                'success' => false,
                'skipped' => false,
                'error' => $error,
                'http_status' => $httpStatus,
                'response' => $responseBody,
            ];
        } catch (\Throwable $e) {
            Log::error('Vodacom SIM activation exception', array_merge($logContext, [
                'exception' => $e->getMessage(),
            ]));

            return [
                'success' => false,
                'skipped' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildActivateQuery(Esim $esim): array
    {
        $query = [];

        if ($esim->msisdn) {
            $query['msisdn'] = VodacomRechargePayload::formatMsisdn((string) $esim->msisdn);
        }

        if ($esim->iccid) {
            $query['iccid'] = trim((string) $esim->iccid);
        }

        if ($esim->imsi) {
            $query['imsi'] = trim((string) $esim->imsi);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function isActivationSuccess(array $responseBody, int $httpStatus): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = strtolower($this->extractErrorMessage($responseBody, $httpStatus));
            if ($this->messageIndicatesAlreadyActive($message)) {
                return true;
            }

            return false;
        }

        $status = strtolower((string) (
            $responseBody['status']
            ?? $responseBody['Status']
            ?? $responseBody['state']
            ?? ''
        ));

        if (in_array($status, ['success', 'successful', 'active', 'activated', 'completed', 'complete', 'approved'], true)) {
            return true;
        }

        if (filter_var($responseBody['success'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $message = strtolower((string) (
            $responseBody['message']
            ?? $responseBody['Message']
            ?? $responseBody['error']
            ?? ''
        ));

        return $this->messageIndicatesAlreadyActive($message);
    }

    private function messageIndicatesAlreadyActive(string $message): bool
    {
        return str_contains($message, 'already active')
            || str_contains($message, 'already activated')
            || str_contains($message, 'already exists');
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function extractErrorMessage(array $responseBody, int $httpStatus): string
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
}
