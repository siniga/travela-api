<?php

namespace App\Services;

use App\Models\Esim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;
use Zxing\QrReader;

class EsimImportService
{
    private const QR_STORAGE_DIR = 'esims/qr-codes';

    /**
     * @return array{
     *     total_pages: int,
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{page: int, message: string}>
     * }
     */
    public function importFromPdf(UploadedFile $file): array
    {
        $summary = [
            'total_pages' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $parser = new Parser();
        $document = $parser->parseFile($file->getRealPath());
        $pages = $document->getPages();
        $summary['total_pages'] = count($pages);

        foreach ($pages as $index => $page) {
            $pageNumber = $index + 1;

            try {
                $result = $this->processPage($page, $pageNumber);

                if ($result['action'] === 'skipped') {
                    $summary['skipped']++;
                    $summary['errors'][] = [
                        'page' => $pageNumber,
                        'message' => $result['message'],
                    ];

                    continue;
                }

                if ($result['action'] === 'imported') {
                    $summary['imported']++;
                } else {
                    $summary['updated']++;
                }
            } catch (\Throwable $e) {
                $summary['skipped']++;
                $summary['errors'][] = [
                    'page' => $pageNumber,
                    'message' => $e->getMessage(),
                ];

                Log::warning('eSIM PDF import page failed', [
                    'page' => $pageNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array{action: 'imported'|'updated'|'skipped', message?: string}
     */
    private function processPage(Page $page, int $pageNumber): array
    {
        $text = trim($page->getText());
        $phoneNumber = $this->extractPhoneNumber($text);

        if (! $phoneNumber) {
            Log::info('eSIM PDF import skipped page', [
                'page' => $pageNumber,
                'reason' => 'no_phone_number',
            ]);

            return [
                'action' => 'skipped',
                'message' => 'No phone number found on page.',
            ];
        }

        $iccid = $this->extractIccid($text);
        $qrPayload = $this->extractQrPayload($page, $pageNumber);
        $qrCodePath = null;

        if (! empty($qrPayload['binary'])) {
            $qrCodePath = $this->storeQrImage($phoneNumber, $qrPayload['binary'], $qrPayload['extension']);
        }

        $existing = Esim::query()->where('msisdn', $phoneNumber)->first();

        $attributes = array_filter([
            'iccid' => $iccid,
            'qr_code_data' => $qrPayload['data'],
            'qr_code_path' => $qrCodePath,
            'sim_type' => Esim::SIM_TYPE_ESIM,
            'provider_status' => Esim::PROVIDER_STATUS_ACTIVE,
            'description' => 'Imported from PDF',
        ], fn ($value) => $value !== null && $value !== '');

        if (! $existing) {
            $attributes['status'] = 'AVAILABLE';
            $attributes['sale_status'] = Esim::SALE_STATUS_AVAILABLE;
            $attributes['network_id'] = 1;
        } else {
            if ($qrCodePath && $existing->qr_code_path && $existing->qr_code_path !== $qrCodePath) {
                Storage::disk('local')->delete($existing->qr_code_path);
            }
            if (! $iccid && $existing->iccid) {
                unset($attributes['iccid']);
            }
            if (! $qrPayload['data'] && $existing->qr_code_data) {
                unset($attributes['qr_code_data']);
            }
            if (! $qrCodePath && $existing->qr_code_path) {
                unset($attributes['qr_code_path']);
            }
        }

        Esim::query()->updateOrCreate(
            ['msisdn' => $phoneNumber],
            $attributes,
        );

        return [
            'action' => $existing === null ? 'imported' : 'updated',
        ];
    }

    public function extractPhoneNumber(string $text): ?string
    {
        $patterns = [
            '/(?:\+?255|00255)(7\d{8})/',
            '/\b(2557\d{8})\b/',
            '/\b(0?7\d{8})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $digits = preg_replace('/\D+/', '', $matches[1]) ?? '';

                if (str_starts_with($digits, '0')) {
                    $digits = '255'.substr($digits, 1);
                } elseif (str_starts_with($digits, '7') && strlen($digits) === 9) {
                    $digits = '255'.$digits;
                }

                if (strlen($digits) >= 12) {
                    return Esim::normalizeMsisdn($digits);
                }
            }
        }

        return null;
    }

    public function extractIccid(string $text): ?string
    {
        if (preg_match('/\b(89\d{17,18})\b/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{data: ?string, binary: ?string, extension: string}
     */
    private function extractQrPayload(Page $page, int $pageNumber): array
    {
        $images = $this->extractImagesFromPage($page);

        foreach ($images as $image) {
            $decoded = $this->decodeQrFromBinary($image['binary']);
            if ($decoded !== null) {
                return [
                    'data' => $decoded,
                    'binary' => $image['binary'],
                    'extension' => $image['extension'],
                ];
            }
        }

        Log::info('eSIM PDF import: no QR decoded', ['page' => $pageNumber]);

        return ['data' => null, 'binary' => null, 'extension' => 'png'];
    }

    /**
     * @return list<array{binary: string, extension: string}>
     */
    private function extractImagesFromPage(Page $page): array
    {
        $images = [];

        try {
            foreach ($page->getXObjects() as $xObject) {
                if (! $xObject instanceof Image) {
                    continue;
                }

                $binary = $xObject->getContent();
                if (! is_string($binary) || $binary === '') {
                    continue;
                }

                $images[] = [
                    'binary' => $binary,
                    'extension' => $this->guessImageExtension($binary),
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('eSIM PDF import: XObject extraction failed', ['error' => $e->getMessage()]);
        }

        return $images;
    }

    private function decodeQrFromBinary(string $binary): ?string
    {
        try {
            $reader = new QrReader($binary, QrReader::SOURCE_TYPE_BLOB);
            $text = $reader->text();

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable) {
            $tempPath = storage_path('app/private/esims/tmp/'.Str::uuid().'.png');
            @mkdir(dirname($tempPath), 0755, true);

            try {
                file_put_contents($tempPath, $binary);
                $reader = new QrReader($tempPath, QrReader::SOURCE_TYPE_FILE);
                $text = $reader->text();

                return is_string($text) && trim($text) !== '' ? trim($text) : null;
            } catch (\Throwable) {
                return null;
            } finally {
                @unlink($tempPath);
            }
        }
    }

    private function storeQrImage(string $phoneNumber, string $binary, string $extension): string
    {
        $safePhone = preg_replace('/\D+/', '', $phoneNumber) ?? 'unknown';
        $filename = $safePhone.'_'.Str::lower(Str::random(8)).'.'.$extension;
        $path = self::QR_STORAGE_DIR.'/'.$filename;

        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    private function guessImageExtension(string $binary): string
    {
        if (str_starts_with($binary, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        return 'png';
    }
}
