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
    ) {
    }

    /**
     * @return array{data: ?string, binary: ?string, extension: string}
     */
    public function extract(Page $page, string $pdfPath): array
    {
        foreach ($this->extractImagesFromPage($page) as $image) {
            $result = $this->tryDecodeImage($image['binary']);
            if ($result !== null) {
                return $result;
            }
        }

        $rasterized = $this->rasterizer->rasterizeFirstPage($pdfPath);
        if ($rasterized !== null) {
            $result = $this->tryDecodeRasterizedPage($rasterized['binary']);
            if ($result !== null) {
                return $result;
            }
        }

        Log::debug('eSIM PDF QR extraction failed: no decodable QR image found');

        return ['data' => null, 'binary' => null, 'extension' => 'png'];
    }

    /**
     * @return array{data: string, binary: string, extension: string}|null
     */
    private function tryDecodeImage(string $binary): ?array
    {
        $normalized = $this->validator->normalizeForStorage($binary);
        if ($normalized === null) {
            return null;
        }

        $decoded = $this->decoder->decode($normalized['binary']);
        if ($decoded === null) {
            return null;
        }

        return [
            'data' => $decoded,
            'binary' => $normalized['binary'],
            'extension' => $normalized['extension'],
        ];
    }

    /**
     * @return array{data: string, binary: string, extension: string}|null
     */
    private function tryDecodeRasterizedPage(string $binary): ?array
    {
        $decoded = $this->decoder->decode($binary);
        if ($decoded !== null) {
            $normalized = $this->validator->normalizeForStorage($binary);
            if ($normalized === null) {
                return null;
            }

            return [
                'data' => $decoded,
                'binary' => $normalized['binary'],
                'extension' => $normalized['extension'],
            ];
        }

        foreach ($this->cropQuadrants($binary) as $crop) {
            $result = $this->tryDecodeImage($crop);
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

                if (! $this->validator->isValid($binary)) {
                    continue;
                }

                $images[] = [
                    'binary' => $binary,
                    'extension' => $this->validator->extension($binary) ?? 'png',
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

            if ($png !== '' && $this->validator->isValid($png)) {
                $crops[] = $png;
            }
        }

        imagedestroy($image);

        return $crops;
    }
}
