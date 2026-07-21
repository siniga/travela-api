<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\XObject\Image;

class PdfQrExtractor
{
    public function __construct(
        private readonly QrImageDecoder $decoder,
        private readonly PdfPageRasterizer $rasterizer,
        private readonly QrImageValidator $validator,
        private readonly QrImageCoercer $coercer,
        private readonly QrRegionScanner $regionScanner,
    ) {
    }

    /**
     * @return array{data: ?string, binary: ?string, extension: string}
     */
    public function extract(Page $page, string $pdfPath, int $pageIndex = 0): array
    {
        $fallbackBinary = null;

        foreach ($this->extractImagesFromPage($page) as $image) {
            $fallbackBinary = $this->pickBetterImageCandidate($fallbackBinary, $image['binary']);
            $result = $this->tryDecodeCandidates($image['binary']);
            if ($result !== null) {
                return $result;
            }
        }

        foreach ($this->rasterizer->rasterizePageVariants($pdfPath, $pageIndex) as $rasterized) {
            $fallbackBinary = $this->pickBetterImageCandidate($fallbackBinary, $rasterized['binary']);
            $result = $this->tryDecodeRasterizedPage($rasterized['binary']);
            if ($result !== null) {
                return $result;
            }
        }

        Log::debug('eSIM PDF QR extraction failed: no decodable QR image found', [
            'pdf' => basename($pdfPath),
        ]);

        $fallback = $this->normalizeImageCandidate($fallbackBinary);
        if ($fallback !== null) {
            return [
                'data' => null,
                'binary' => $fallback['binary'],
                'extension' => $fallback['extension'],
            ];
        }

        return ['data' => null, 'binary' => null, 'extension' => 'png'];
    }

    /**
     * @return array{data: string, binary: string, extension: string}|null
     */
    private function tryDecodeCandidates(string $binary): ?array
    {
        foreach ($this->coercer->candidates($binary) as $candidate) {
            $decoded = $this->decoder->decode($candidate['binary']);
            if ($decoded === null) {
                continue;
            }

            $normalized = $this->validator->normalizeForStorage($candidate['binary']);
            if ($normalized === null) {
                continue;
            }

            return [
                'data' => $decoded,
                'binary' => $normalized['binary'],
                'extension' => $normalized['extension'],
            ];
        }

        return null;
    }

    /**
     * @return array{data: string, binary: string, extension: string}|null
     */
    private function tryDecodeRasterizedPage(string $binary): ?array
    {
        $result = $this->tryDecodeCandidates($binary);
        if ($result !== null) {
            return $result;
        }

        foreach ($this->regionScanner->scanRegions($binary) as $region) {
            $result = $this->tryDecodeCandidates($region);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
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
                    'extension' => $this->validator->extension($binary) ?? 'png',
                    'valid' => $this->validator->isValid($binary),
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('eSIM single import: XObject extraction failed', ['error' => $e->getMessage()]);
        }

        usort($images, function (array $a, array $b): int {
            if ($a['valid'] !== $b['valid']) {
                return $a['valid'] ? -1 : 1;
            }

            return strlen($b['binary']) <=> strlen($a['binary']);
        });

        return array_map(
            fn (array $image): array => [
                'binary' => $image['binary'],
                'extension' => $image['extension'],
            ],
            $images,
        );
    }

    private function pickBetterImageCandidate(?string $current, string $candidate): ?string
    {
        $normalized = $this->normalizeImageCandidate($candidate);
        if ($normalized === null) {
            return $current;
        }

        if ($current === null) {
            return $normalized['binary'];
        }

        $currentNormalized = $this->normalizeImageCandidate($current);
        if ($currentNormalized === null) {
            return $normalized['binary'];
        }

        return strlen($normalized['binary']) > strlen($currentNormalized['binary'])
            ? $normalized['binary']
            : $current;
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    private function normalizeImageCandidate(?string $binary): ?array
    {
        if ($binary === null || $binary === '') {
            return null;
        }

        $normalized = $this->validator->normalizeForStorage($binary);
        if ($normalized === null) {
            return null;
        }

        return [
            'binary' => $normalized['binary'],
            'extension' => $normalized['extension'],
        ];
    }
}
