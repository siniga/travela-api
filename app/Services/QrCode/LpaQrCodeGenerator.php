<?php

namespace App\Services\QrCode;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class LpaQrCodeGenerator
{
    /**
     * Encode activation payload as a PNG data URI suitable for inline email images.
     */
    public function pngDataUri(string $payload): ?string
    {
        $value = $this->encodeValue($payload);
        if ($value === null) {
            return null;
        }

        if (! extension_loaded('gd')) {
            return null;
        }

        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'scale' => 10,
            'eccLevel' => EccLevel::M,
        ]);

        $dataUri = (new QRCode($options))->render($value);

        return is_string($dataUri) && str_starts_with($dataUri, 'data:image/png;base64,')
            ? $dataUri
            : null;
    }

    /**
     * Normalize imported activation payload to the string encoded in the QR symbol.
     */
    public function encodeValue(string $payload): ?string
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        $lpa = $this->normalizeLpaPayload($trimmed);

        return $lpa ?? $trimmed;
    }

    public function normalizeLpaPayload(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^LPA:1\$/i', $trimmed) === 1) {
            return $trimmed;
        }

        if (preg_match('/^[^$\s]+\$.+/', $trimmed) === 1 && ! str_contains($trimmed, '://')) {
            return 'LPA:1$'.$trimmed;
        }

        return null;
    }
}
