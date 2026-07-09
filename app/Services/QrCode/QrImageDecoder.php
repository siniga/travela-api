<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Zxing\QrReader;

class QrImageDecoder
{
    /**
     * Decode a QR code from raw image bytes, trying several preprocessed variants.
     */
    public function decode(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        foreach ($this->buildVariants($binary) as $index => $variant) {
            $decoded = $this->decodeOnce($variant['binary'], $variant['extension']);
            if ($decoded !== null) {
                if ($index > 0) {
                    Log::debug('eSIM QR decode succeeded on image variant', ['variant' => $index]);
                }

                return $decoded;
            }
        }

        Log::debug('eSIM QR decode failed for all image variants', [
            'bytes' => strlen($binary),
            'extension' => $this->guessImageExtension($binary),
        ]);

        return null;
    }

    /**
     * @return list<array{binary: string, extension: string}>
     */
    public function buildVariants(string $binary): array
    {
        $extension = $this->guessImageExtension($binary);
        $variants = [
            ['binary' => $binary, 'extension' => $extension],
        ];

        if (extension_loaded('gd')) {
            foreach ($this->preprocessWithGd($binary) as $processed) {
                $variants[] = $processed;
            }
        }

        return $this->uniqueVariants($variants);
    }

    public function guessImageExtension(string $binary): string
    {
        if (str_starts_with($binary, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        return 'png';
    }

    private function decodeOnce(string $binary, string $extension): ?string
    {
        try {
            $reader = new QrReader($binary, QrReader::SOURCE_TYPE_BLOB);
            $text = $reader->text();

            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        } catch (\Throwable $e) {
            Log::debug('eSIM QR blob decode failed', ['error' => $e->getMessage()]);
        }

        $tempPath = storage_path('app/private/esims/tmp/'.Str::uuid().'.'.$extension);
        @mkdir(dirname($tempPath), 0755, true);

        try {
            file_put_contents($tempPath, $binary);
            $reader = new QrReader($tempPath, QrReader::SOURCE_TYPE_FILE);
            $text = $reader->text();

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::debug('eSIM QR file decode failed', [
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @return list<array{binary: string, extension: string}>
     */
    private function preprocessWithGd(string $binary): array
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return [];
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);

            return [];
        }

        $variants = [];

        foreach ($this->scaleTargets($width, $height) as $targetWidth) {
            $scaled = $this->resizeImage($image, $width, $height, $targetWidth);
            if ($scaled !== null) {
                $variants[] = ['binary' => $scaled, 'extension' => 'png'];
            }
        }

        $grayscale = $this->toGrayscale($image, $width, $height);
        if ($grayscale !== null) {
            $variants[] = ['binary' => $grayscale, 'extension' => 'png'];
            $variants[] = ['binary' => $this->adjustContrast($grayscale, 30), 'extension' => 'png'];
            $variants[] = ['binary' => $this->adjustContrast($grayscale, -30), 'extension' => 'png'];
        }

        imagedestroy($image);

        return array_values(array_filter($variants, fn (array $variant) => $variant['binary'] !== ''));
    }

    /**
     * @return list<int>
     */
    private function scaleTargets(int $width, int $height): array
    {
        $longest = max($width, $height);
        $targets = [];

        if ($longest < 600) {
            $targets[] = (int) round($width * (900 / $longest));
        }

        if ($longest < 900) {
            $targets[] = (int) round($width * (1200 / $longest));
        }

        if ($longest > 1800) {
            $targets[] = (int) round($width * (1200 / $longest));
        }

        return array_values(array_unique(array_filter($targets, fn (int $value) => $value > 0)));
    }

    private function resizeImage(\GdImage $image, int $width, int $height, int $targetWidth): ?string
    {
        if ($targetWidth === $width) {
            return null;
        }

        $targetHeight = (int) round($height * ($targetWidth / $width));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            return null;
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $this->imageToPngBinary($resized);
    }

    private function toGrayscale(\GdImage $image, int $width, int $height): ?string
    {
        $gray = imagecreatetruecolor($width, $height);
        if ($gray === false) {
            return null;
        }

        imagecopy($gray, $image, 0, 0, 0, 0, $width, $height);
        imagefilter($gray, IMG_FILTER_GRAYSCALE);

        return $this->imageToPngBinary($gray);
    }

    private function adjustContrast(string $pngBinary, int $level): string
    {
        $image = @imagecreatefromstring($pngBinary);
        if ($image === false) {
            return '';
        }

        imagefilter($image, IMG_FILTER_CONTRAST, $level);

        return $this->imageToPngBinary($image);
    }

    private function imageToPngBinary(\GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $binary = ob_get_clean() ?: '';
        imagedestroy($image);

        return $binary;
    }

    /**
     * @param  list<array{binary: string, extension: string}>  $variants
     * @return list<array{binary: string, extension: string}>
     */
    private function uniqueVariants(array $variants): array
    {
        $seen = [];
        $unique = [];

        foreach ($variants as $variant) {
            if ($variant['binary'] === '') {
                continue;
            }

            $hash = md5($variant['binary']);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $unique[] = $variant;
        }

        return $unique;
    }
}
