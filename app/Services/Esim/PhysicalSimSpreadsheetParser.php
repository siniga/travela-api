<?php

namespace App\Services\Esim;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class PhysicalSimSpreadsheetParser
{
    private const MAX_ROWS = 10000;

    /**
     * Parse a spreadsheet into rows of msisdn + iccid.
     * Expects a header row containing "msisdn" and "iccid" (case-insensitive).
     *
     * @return list<array{msisdn: string, iccid: string, row_number: int}>
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('Physical SIM import accepts .xlsx, .xls, or .csv files only.');
        }

        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setReadEmptyCells')) {
                $reader->setReadEmptyCells(false);
            }
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read spreadsheet: '.$e->getMessage(), 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) {
            throw new RuntimeException('Spreadsheet must include a header row and at least one data row.');
        }

        $headerRow = array_shift($rows);
        $columns = $this->resolveColumns($headerRow);

        $parsed = [];
        $seenMsisdn = [];
        $seenIccid = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1-based, after header
            $msisdnRaw = $this->cellValue($row, $columns['msisdn']);
            $iccidRaw = $this->cellValue($row, $columns['iccid']);

            if ($msisdnRaw === '' && $iccidRaw === '') {
                continue;
            }

            if ($msisdnRaw === '' || $iccidRaw === '') {
                throw new RuntimeException("Row {$rowNumber}: both msisdn and iccid are required.");
            }

            $msisdn = preg_replace('/\s+/', '', $msisdnRaw) ?? $msisdnRaw;
            $msisdn = ltrim($msisdn, '+');
            $iccid = strtoupper(preg_replace('/\s+/', '', $iccidRaw) ?? $iccidRaw);

            if ($msisdn === '' || $iccid === '') {
                throw new RuntimeException("Row {$rowNumber}: both msisdn and iccid are required.");
            }

            if (isset($seenMsisdn[$msisdn])) {
                throw new RuntimeException("Row {$rowNumber}: duplicate msisdn {$msisdn} (also on row {$seenMsisdn[$msisdn]}).");
            }
            if (isset($seenIccid[$iccid])) {
                throw new RuntimeException("Row {$rowNumber}: duplicate iccid {$iccid} (also on row {$seenIccid[$iccid]}).");
            }

            $seenMsisdn[$msisdn] = $rowNumber;
            $seenIccid[$iccid] = $rowNumber;

            $parsed[] = [
                'msisdn' => $msisdn,
                'iccid' => $iccid,
                'row_number' => $rowNumber,
            ];

            if (count($parsed) > self::MAX_ROWS) {
                throw new RuntimeException('Spreadsheet exceeds the maximum of '.self::MAX_ROWS.' data rows.');
            }
        }

        if ($parsed === []) {
            throw new RuntimeException('No data rows found in spreadsheet.');
        }

        return $parsed;
    }

    /**
     * @param  array<int, mixed>  $headerRow
     * @return array{msisdn: int, iccid: int}
     */
    private function resolveColumns(array $headerRow): array
    {
        $msisdnIndex = null;
        $iccidIndex = null;

        foreach ($headerRow as $index => $value) {
            $normalized = strtolower(trim((string) $value));
            if ($normalized === 'msisdn' || $normalized === 'phone' || $normalized === 'phone_number') {
                $msisdnIndex = (int) $index;
            }
            if ($normalized === 'iccid') {
                $iccidIndex = (int) $index;
            }
        }

        if ($msisdnIndex === null || $iccidIndex === null) {
            throw new RuntimeException('Spreadsheet header must include "msisdn" and "iccid" columns.');
        }

        return [
            'msisdn' => $msisdnIndex,
            'iccid' => $iccidIndex,
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function cellValue(array $row, int $index): string
    {
        if (! array_key_exists($index, $row) || $row[$index] === null) {
            return '';
        }

        $value = $row[$index];
        if (is_float($value) || is_int($value)) {
            // Avoid scientific notation for long numeric ICCIDs/MSISDNs.
            if (is_float($value) && floor($value) === $value) {
                return sprintf('%.0f', $value);
            }

            return (string) $value;
        }

        return trim((string) $value);
    }
}
