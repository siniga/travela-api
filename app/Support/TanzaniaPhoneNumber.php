<?php

namespace App\Support;

use InvalidArgumentException;

class TanzaniaPhoneNumber
{
    /**
     * Normalize a Tanzanian number to 255XXXXXXXXX.
     */
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && preg_match('/^[67]/', $digits) === 1) {
            $digits = '255'.$digits;
        }

        if (! self::isValid($digits)) {
            throw new InvalidArgumentException('Phone number must be a valid Tanzanian MSISDN (255XXXXXXXXX).');
        }

        return $digits;
    }

    public static function isValid(string $normalized): bool
    {
        return preg_match('/^255[1-9]\d{8}$/', $normalized) === 1;
    }

    public static function tryNormalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return self::normalize($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
