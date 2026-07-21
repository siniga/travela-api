<?php

namespace App\Services;

use App\Models\Esim;
use App\Models\EsimImportBatch;
use App\Models\EsimImportItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use App\Services\QrCode\PdfPageRasterizer;
use App\Services\QrCode\PdfQrExtractor;
use App\Services\QrCode\QrImageCoercer;
use App\Services\QrCode\QrImageDecoder;
use App\Services\QrCode\QrImageValidator;
use App\Services\QrCode\QrRegionScanner;
use Illuminate\Http\UploadedFile;

class EsimSingleImportService
{
    private const QR_STORAGE_DIR = 'esims/qr-codes';

    private const SOURCE_STORAGE_DIR = 'esims/import-sources';

    private readonly QrImageDecoder $qrDecoder;

    private readonly QrImageValidator $qrImageValidator;

    private readonly PdfQrExtractor $pdfQrExtractor;

    public function __construct(
        private readonly VodacomSimActivationService $vodacomActivation,
        ?QrImageDecoder $qrDecoder = null,
        ?QrImageValidator $qrImageValidator = null,
        ?PdfQrExtractor $pdfQrExtractor = null,
    ) {
        $this->qrDecoder = $qrDecoder ?? new QrImageDecoder();
        $this->qrImageValidator = $qrImageValidator ?? new QrImageValidator();
        $this->pdfQrExtractor = $pdfQrExtractor ?? new PdfQrExtractor(
            $this->qrDecoder,
            new PdfPageRasterizer(),
            $this->qrImageValidator,
            new QrImageCoercer($this->qrImageValidator),
            new QrRegionScanner(),
        );
    }

    /**
     * Process one uploaded file for a batch import item.
     *
     * @return array{esim: Esim, created: bool}
     */
    public function process(
        EsimImportBatch $batch,
        EsimImportItem $item,
        UploadedFile $file,
        ?string $phoneOverride = null,
        ?string $iccidOverride = null,
    ): array {
        $phoneOverride = $this->resolvePhoneOverride($phoneOverride, $item->phone_number);
        $iccidOverride = $this->resolveIccidOverride($iccidOverride, $item->iccid);

        $sourcePath = $this->storeSourceFile($batch->id, $item->id, $file);
        $item->update(['source_file_path' => $sourcePath]);

        $mime = strtolower($file->getMimeType() ?? '');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $isPdf = $extension === 'pdf' || str_contains($mime, 'pdf');

        $text = '';
        $qrBinary = null;
        $qrCodeData = null;

        if ($isPdf) {
            $page = $this->loadSinglePdfPage($file);
            $text = trim($page->getText());
            $qrPayload = $this->pdfQrExtractor->extract($page, $file->getRealPath());
            $qrBinary = $qrPayload['binary'];
            $qrCodeData = $qrPayload['data'];
        } else {
            $qrBinary = file_get_contents($file->getRealPath()) ?: null;
        }

        $phoneNumber = $phoneOverride
            ? Esim::normalizeMsisdn($phoneOverride)
            : $this->extractPhoneNumber($text);

        if ($qrBinary && $qrCodeData === null) {
            $qrCodeData = $this->qrDecoder->decode($qrBinary);
        }

        if ($qrCodeData) {
            if (! $phoneNumber) {
                $phoneNumber = $this->extractPhoneNumber($qrCodeData) ?? $this->extractPhoneFromQrData($qrCodeData);
            }
            if (! $iccidOverride) {
                $iccidOverride = $this->extractIccid($qrCodeData) ?? $iccidOverride;
            }
        }

        if (! $phoneNumber) {
            throw new \RuntimeException('Phone number not found.');
        }

        $iccid = $iccidOverride ?: $this->extractIccid($text);

        $qrPath = null;
        if ($qrBinary) {
            $normalized = $this->qrImageValidator->normalizeForStorage($qrBinary);

            if ($normalized !== null) {
                $qrPath = $this->storeQrImage(
                    $phoneNumber,
                    $normalized['binary'],
                    $normalized['extension'],
                );
                $item->update(['qr_code_path' => $qrPath]);
            }
        }

        $existing = Esim::query()->where('msisdn', $phoneNumber)->first();

        $batchSimType = $batch->resolvedSimType();
        $importDescription = $batchSimType === Esim::SIM_TYPE_PHYSICAL
            ? 'Imported physical card via batch #'.$batch->id
            : 'Imported eSIM via batch #'.$batch->id;

        $attributes = array_filter([
            'import_batch_id' => $batch->id,
            'iccid' => $iccid,
            'qr_code_path' => $qrPath,
            'qr_code_data' => $qrCodeData,
            'sim_type' => $batchSimType,
            'description' => $importDescription,
        ], fn ($value) => $value !== null && $value !== '');

        if (! $existing) {
            $attributes['status'] = 'AVAILABLE';
            $attributes['sale_status'] = Esim::SALE_STATUS_AVAILABLE;
            $attributes['network_id'] = 1;
            $attributes['provider_status'] = Esim::PROVIDER_STATUS_SUSPENDED;
            $attributes['activation_status'] = Esim::ACTIVATION_STATUS_PENDING;
        } else {
            if ($qrPath && $existing->qr_code_path && $existing->qr_code_path !== $qrPath) {
                Storage::disk('local')->delete($existing->qr_code_path);
            }
            if (! $iccid && $existing->iccid) {
                unset($attributes['iccid']);
            }
            if (! $qrCodeData && $existing->qr_code_data) {
                unset($attributes['qr_code_data']);
            }
            if (! $qrPath && $existing->qr_code_path) {
                unset($attributes['qr_code_path']);
            }

            if (! $this->vodacomActivation->isActivated($existing)) {
                $attributes['provider_status'] = Esim::PROVIDER_STATUS_SUSPENDED;
                $attributes['activation_status'] = Esim::ACTIVATION_STATUS_PENDING;
                $attributes['activation_error'] = null;
            }
        }

        $esim = Esim::query()->updateOrCreate(
            ['msisdn' => $phoneNumber],
            $attributes,
        );

        if (! $this->vodacomActivation->isActivated($esim)) {
            $esim = $this->vodacomActivation->activate($esim);
        }

        return [
            'esim' => $esim->fresh(),
            'created' => $existing === null,
        ];
    }

    /**
     * Re-process a failed item using stored source file or a new upload.
     *
     * @return array{esim: Esim, created: bool}
     */
    public function retry(
        EsimImportBatch $batch,
        EsimImportItem $item,
        ?UploadedFile $file = null,
        ?string $phoneOverride = null,
        ?string $iccidOverride = null,
    ): array {
        if ($file) {
            return $this->process($batch, $item, $file, $phoneOverride, $iccidOverride);
        }

        if (! $item->source_file_path || ! Storage::disk('local')->exists($item->source_file_path)) {
            throw new \RuntimeException('No source file available for retry. Upload a new file.');
        }

        $tempPath = storage_path('app/private/esims/tmp/retry-'.$item->id.'-'.Str::uuid());
        @mkdir(dirname($tempPath), 0755, true);
        file_put_contents($tempPath, Storage::disk('local')->get($item->source_file_path));

        $uploaded = new UploadedFile(
            $tempPath,
            basename($item->source_file_path),
            null,
            null,
            true,
        );

        try {
            return $this->process(
                $batch,
                $item,
                $uploaded,
                $phoneOverride ?? $item->phone_number,
                $iccidOverride ?? $item->iccid,
            );
        } finally {
            @unlink($tempPath);
        }
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
        if (preg_match('/\b(89[\dA-Fa-f]{17,18})\b/', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function extractPhoneFromQrData(string $qrData): ?string
    {
        if (preg_match('/(?:msisdn|phone|tel)[=:]?\s*(\+?2557\d{8}|2557\d{8})/i', $qrData, $matches)) {
            return Esim::normalizeMsisdn($matches[1]);
        }

        return null;
    }

    private function resolvePhoneOverride(?string $override, ?string $itemValue): ?string
    {
        if (is_string($override) && trim($override) !== '') {
            return $override;
        }

        if (is_string($itemValue) && trim($itemValue) !== '') {
            return $itemValue;
        }

        return null;
    }

    private function resolveIccidOverride(?string $override, ?string $itemValue): ?string
    {
        if (is_string($override) && trim($override) !== '') {
            return strtoupper(trim($override));
        }

        if (is_string($itemValue) && trim($itemValue) !== '') {
            return strtoupper(trim($itemValue));
        }

        return null;
    }

    private function loadSinglePdfPage(UploadedFile $file): Page
    {
        $parser = new Parser();
        $document = $parser->parseFile($file->getRealPath());
        $pages = $document->getPages();

        if ($pages === []) {
            throw new \RuntimeException('PDF contains no pages.');
        }

        return $pages[0];
    }

    private function storeSourceFile(int $batchId, int $itemId, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = self::SOURCE_STORAGE_DIR.'/'.$batchId.'/'.$itemId.'.'.$extension;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()) ?: '');

        return $path;
    }

    private function storeQrImage(string $phoneNumber, string $binary, string $extension): string
    {
        $safePhone = preg_replace('/\D+/', '', $phoneNumber) ?? 'unknown';
        $ext = $extension === 'jpg' || $extension === 'jpeg' ? 'jpg' : 'png';
        $path = self::QR_STORAGE_DIR.'/'.$safePhone.'.'.$ext;

        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
