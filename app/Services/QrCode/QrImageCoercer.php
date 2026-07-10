<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;

class QrImageCoercer
{
    public function __construct(
        private readonly QrImageValidator $validator,
    ) {
    }

    /**
     * Build decode candidates from raw PDF image bytes (with or without standard headers).
     *
     * @return list<array{binary: string, extension: string}>
     */
    public function candidates(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        $seen = [];
        $candidates = [];

        $push = function (string $payload, string $extension) use (&$seen, &$candidates): void {
            if ($payload === '') {
                return;
            }

            $hash = md5($payload.'|'.$extension);
            if (isset($seen[$hash])) {
                return;
            }

            $seen[$hash] = true;
            $candidates[] = ['binary' => $payload, 'extension' => $extension];
        };

        $normalized = $this->validator->normalizeForStorage($binary);
        if ($normalized !== null) {
            $push($normalized['binary'], $normalized['extension']);

            return $candidates;
        }

        $push($binary, $this->validator->extension($binary) ?? 'png');
        $push($binary, 'jpg');

        if (extension_loaded('gd')) {
            $coerced = $this->coerceWithGd($binary);
            if ($coerced !== null) {
                $push($coerced, 'png');
            }
        }

        return $candidates;
    }

    private function coerceWithGd(string $binary): ?string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean() ?: '';
        imagedestroy($image);

        if ($png === '' || ! str_starts_with($png, "\x89PNG")) {
            Log::debug('eSIM QR coerce failed to produce PNG', ['bytes' => strlen($binary)]);

            return null;
        }

        return $png;
    }
}
