<?php

namespace App\Services;

use App\Models\Esim;
use App\Models\UserEsim;
use Illuminate\Support\Facades\Log;

class VodacomBalanceService
{
    private const BALANCE_KEYS = ['AIRTIME', 'DATA', 'SMS'];

    /**
     * @param  array<string, mixed>  $raw
     * @return array{AIRTIME: float|null, DATA: float|null, SMS: float|null}
     */
    public function normalizeBalances(array $raw): array
    {
        $normalized = [];

        foreach (self::BALANCE_KEYS as $key) {
            $value = $raw[$key] ?? $raw[strtolower($key)] ?? null;
            $normalized[$key] = ($value === null || $value === '') ? null : (float) $value;
        }

        return $normalized;
    }

    /**
     * @param  array{msisdn?: string, balances?: array<string, mixed>, balance?: float|int|string, currency?: string}  $item
     * @return array{esim_id: int, assignment_updated: bool}|null
     */
    public function applyPayload(array $item): ?array
    {
        $record = $this->normalizeRecord($item);

        if ($record === null) {
            return null;
        }

        $msisdn = $record['msisdn'];
        $esim = Esim::findByMsisdn($msisdn);

        if (! $esim) {
            Log::warning('Vodacom balance: SIM not in inventory', ['msisdn' => $msisdn, 'payload' => $item]);

            return null;
        }

        $balances = isset($record['balances']) && is_array($record['balances'])
            ? $this->normalizeBalances($record['balances'])
            : ['AIRTIME' => isset($record['balance']) ? (float) $record['balance'] : null, 'DATA' => null, 'SMS' => null];

        $currency = is_string($record['currency'] ?? null) ? $record['currency'] : 'TZS';
        $fetchedAt = now();

        $esim->update([
            'balances' => $balances,
            'balance_fetched_at' => $fetchedAt,
        ]);

        $assignment = UserEsim::where('esim_id', $esim->id)->first();
        $assignmentUpdated = false;

        if ($assignment) {
            $assignment->update([
                'balances' => $balances,
                'balance' => $balances['AIRTIME'],
                'balance_currency' => $currency,
                'balance_fetched_at' => $fetchedAt,
            ]);
            $assignmentUpdated = true;
        } else {
            Log::info('Vodacom balance: stored on esims only (no user assignment)', [
                'esim_id' => $esim->id,
                'msisdn' => $esim->msisdn,
            ]);
        }

        return [
            'esim_id' => $esim->id,
            'assignment_updated' => $assignmentUpdated,
        ];
    }

    /**
     * @return list<array{esim_id: int, assignment_updated: bool}>
     */
    public function syncFromVodacomPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $records = $this->extractBalanceRecords($payload);

        if ($records === []) {
            Log::warning('Vodacom balance: unrecognized payload shape', [
                'keys' => array_keys($payload),
                'json_preview' => mb_substr(json_encode($payload), 0, 2000),
            ]);

            return [];
        }

        $results = [];

        foreach ($records as $record) {
            $result = $this->applyPayload($record);
            if ($result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractBalanceRecords(array $payload, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        foreach (['data', 'result', 'response', 'body', 'content', 'payload'] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key])) {
                continue;
            }

            $decoded = json_decode($payload[$key], true);
            if (is_array($decoded)) {
                $nested = $this->extractBalanceRecords($decoded, $depth + 1);
                if ($nested !== []) {
                    return $nested;
                }
            }
        }

        $record = $this->normalizeRecord($payload);
        if ($record !== null) {
            return [$record];
        }

        if ($this->isListOfBalanceRecords($payload)) {
            $records = [];
            foreach ($payload as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $normalized = $this->normalizeRecord($item);
                if ($normalized !== null) {
                    $records[] = $normalized;
                }
            }

            return $records;
        }

        foreach (['sim', 'esim', 'simData', 'sim_data'] as $nestedKey) {
            if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
                $nested = $this->extractBalanceRecords($payload[$nestedKey], $depth + 1);
                if ($nested !== []) {
                    return $nested;
                }
            }
        }

        foreach (['data', 'result', 'response', 'body', 'content', 'payload', 'items', 'sims', 'results', 'records'] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                continue;
            }

            $nested = $this->extractBalanceRecords($payload[$key], $depth + 1);
            if ($nested !== []) {
                return $nested;
            }
        }

        foreach ($payload as $value) {
            if (! is_array($value)) {
                continue;
            }

            $nested = $this->extractBalanceRecords($value, $depth + 1);
            if ($nested !== []) {
                return $nested;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{msisdn: string, balances?: array<string, mixed>, balance?: float, currency?: string}|null
     */
    private function normalizeRecord(array $item): ?array
    {
        $msisdn = $item['msisdn']
            ?? $item['MSISDN']
            ?? $item['phoneNumber']
            ?? $item['phone_number']
            ?? null;

        if (! is_string($msisdn) || trim($msisdn) === '') {
            return null;
        }

        $balances = $item['balances'] ?? $item['Balances'] ?? null;

        $record = ['msisdn' => $msisdn];

        if (is_array($balances)) {
            $record['balances'] = $balances;
        } elseif (isset($item['balance']) && is_array($item['balance'])) {
            $record['balances'] = $item['balance'];
        } elseif (isset($item['balance']) && ! is_array($item['balance'])) {
            $record['balance'] = $item['balance'];
        } else {
            $typed = [];
            foreach (self::BALANCE_KEYS as $key) {
                if (array_key_exists($key, $item)) {
                    $typed[$key] = $item[$key];
                }
            }

            if ($typed === []) {
                return null;
            }

            $record['balances'] = $typed;
        }

        if (isset($item['currency']) && is_string($item['currency'])) {
            $record['currency'] = $item['currency'];
        }

        return $record;
    }

    /**
     * @param  array<mixed>  $items
     */
    private function isListOfBalanceRecords(array $items): bool
    {
        if (! array_is_list($items) || $items === []) {
            return false;
        }

        $first = $items[0];

        return is_array($first) && $this->normalizeRecord($first) !== null;
    }
}
