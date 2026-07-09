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
    ) {
    }

    /**
     * @return array{data: ?string, binary: ?string, extension: string}
     */
    public function extract(Page $page, string $pdfPath): array
    {
        $images = $this->extractImagesFromPage($page);

        foreach ($this->sortImagesByDecodePriority($images) as $image) {
            $decoded = $this->decoder->decode($image['binary']);
            if ($decoded !== null) {
                return [
                    'data' => $decoded,
                    'binary' => $image['binary'],
                    'extension' => $image['extension'],
                ];
            }
        }

        $largest = $images[0] ?? null;
        $rasterized = $this->rasterizer->rasterizeFirstPage($pdfPath);
        if ($rasterized !== null) {
            $decoded = $this->decodeRasterizedPage($rasterized['binary']);
            if ($decoded !== null) {
                return [
                    'data' => $decoded['text'],
                    'binary' => $decoded['binary'],
                    'extension' => $rasterized['extension'],
                ];
            }

            if ($largest === null || strlen($rasterized['binary']) > strlen($largest['binary'])) {
                $largest = $rasterized;
            }
        }

        if ($largest !== null) {
            Log::debug('eSIM PDF QR image kept without decoded payload', [
                'bytes' => strlen($largest['binary']),
                'extension' => $largest['extension'],
            ]);

            return [
                'data' => null,
                'binary' => $largest['binary'],
                'extension' => $largest['extension'],
            ];
        }

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
                    'extension' => $this->decoder->guessImageExtension($binary),
                ];
            }
        } catch (\Throwable $e) {
            Log::debug('eSIM single import: XObject extraction failed', ['error' => $e->getMessage()]);
        }

        return $this->sortImagesByDecodePriority($images);
    }

    /**
     * Larger embedded images are more likely to contain the QR code.
     *
     * @param  list<array{binary: string, extension: string}>  $images
     * @return list<array{binary: string, extension: string}>
     */
    private function sortImagesByDecodePriority(array $images): array
    {
        usort($images, fn (array $a, array $b) => strlen($b['binary']) <=> strlen($a['binary']));

        return $images;
    }

    /**
     * @return array{text: string, binary: string}|null
     */
    private function decodeRasterizedPage(string $binary): ?array
    {
        $decoded = $this->decoder->decode($binary);
        if ($decoded !== null) {
            return ['text' => $decoded, 'binary' => $binary];
        }

        foreach ($this->cropQuadrants($binary) as $crop) {
            $decoded = $this->decoder->decode($crop);
            if ($decoded !== null) {
                return ['text' => $decoded, 'binary' => $crop];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function cropQuadrants(string $binary): array
    {
        if (! extension_loaded('gd')) {
            return [];
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return [];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 2) {
            imagedestroy($image);

            return [];
        }

        $crops = [];
        $halfWidth = (int) floor($width / 2);
        $halfHeight = (int) floor($height / 2);
        $regions = [
            [0, 0, $halfWidth, $halfHeight],
            [$halfWidth, 0, $width - $halfWidth, $halfHeight],
            [0, $halfHeight, $halfWidth, $height - $halfHeight],
            [$halfWidth, $halfHeight, $width - $halfWidth, $height - $halfHeight],
        ];

        foreach ($regions as [$x, $y, $regionWidth, $regionHeight]) {
            $crop = imagecrop($image, [
                'x' => $x,
                'y' => $y,
                'width' => $regionWidth,
                'height' => $regionHeight,
            ]);

            if ($crop === false) {
                continue;
            }

            ob_start();
            imagepng($crop);
            $png = ob_get_clean() ?: '';
            imagedestroy($crop);

            if ($png !== '') {
                $crops[] = $png;
            }
        }

        imagedestroy($image);

        return $crops;
    }
}
