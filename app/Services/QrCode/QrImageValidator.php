<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;

class QrImageValidator
{
    public function isValid(string $binary): bool
    {
        return $this->mimeType($binary) !== null;
    }

    public function mimeType(string $binary): ?string
    {
        if (str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        return null;
    }

    public function extension(string $binary): ?string
    {
        return match ($this->mimeType($binary)) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => null,
        };
    }

    /**
     * Ensure stored QR bytes are a browser-renderable PNG or JPEG.
     *
     * @return array{binary: string, extension: string, mime: string}|null
     */
    public function normalizeForStorage(string $binary): ?array
    {
        if ($binary === '') {
            return null;
        }

        $mime = $this->mimeType($binary);
        if ($mime === null) {
            Log::debug('eSIM QR image rejected: unsupported or corrupt binary', [
                'bytes' => strlen($binary),
            ]);

            return null;
        }

        if (extension_loaded('gd')) {
            $reencoded = $this->reencodeWithGd($binary);
            if ($reencoded !== null) {
                return $reencoded;
            }
        }

        $extension = $this->extension($binary);
        if ($extension === null) {
            return null;
        }

        return [
            'binary' => $binary,
            'extension' => $extension,
            'mime' => $mime,
        ];
    }

    /**
     * @return array{binary: string, extension: string, mime: string}|null
     */
    private function reencodeWithGd(string $binary): ?array
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            Log::debug('eSIM QR image rejected: GD could not parse bytes', [
                'bytes' => strlen($binary),
                'mime' => $this->mimeType($binary),
            ]);

            return null;
        }

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean() ?: '';
        imagedestroy($image);

        if ($png === '' || ! str_starts_with($png, "\x89PNG")) {
            return null;
        }

        return [
            'binary' => $png,
            'extension' => 'png',
            'mime' => 'image/png',
        ];
    }
}
