<?php

namespace App\Services\QrCode;

class QrRegionScanner
{
    /**
     * @return list<string> PNG binaries to scan for QR codes
     */
    public function scanRegions(string $binary): array
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
        if ($width < 8 || $height < 8) {
            imagedestroy($image);

            return [];
        }

        $regions = [];
        $regions = array_merge($regions, $this->fractionalRegions($width, $height, 0.5, 0.5));
        $regions = array_merge($regions, $this->fractionalRegions($width, $height, 0.7, 0.7));
        $regions = array_merge($regions, $this->gridRegions($width, $height, 2));
        $regions = array_merge($regions, $this->gridRegions($width, $height, 3));

        $pngs = [];
        $seen = [];

        foreach ($regions as [$x, $y, $regionWidth, $regionHeight]) {
            $crop = imagecrop($image, [
                'x' => $x,
                'y' => $y,
                'width' => max(1, $regionWidth),
                'height' => max(1, $regionHeight),
            ]);

            if ($crop === false) {
                continue;
            }

            ob_start();
            imagepng($crop);
            $png = ob_get_clean() ?: '';
            imagedestroy($crop);

            if ($png === '') {
                continue;
            }

            $hash = md5($png);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $pngs[] = $png;
        }

        imagedestroy($image);

        return $pngs;
    }

    /**
     * @return list<array{int, int, int, int}>
     */
    private function fractionalRegions(int $width, int $height, float $widthRatio, float $heightRatio): array
    {
        $regionWidth = (int) round($width * $widthRatio);
        $regionHeight = (int) round($height * $heightRatio);

        return [[
            (int) round(($width - $regionWidth) / 2),
            (int) round(($height - $regionHeight) / 2),
            $regionWidth,
            $regionHeight,
        ]];
    }

    /**
     * @return list<array{int, int, int, int}>
     */
    private function gridRegions(int $width, int $height, int $divisions): array
    {
        $regions = [];
        $cellWidth = (int) floor($width / $divisions);
        $cellHeight = (int) floor($height / $divisions);

        for ($row = 0; $row < $divisions; $row++) {
            for ($col = 0; $col < $divisions; $col++) {
                $regions[] = [
                    $col * $cellWidth,
                    $row * $cellHeight,
                    $col === $divisions - 1 ? $width - ($col * $cellWidth) : $cellWidth,
                    $row === $divisions - 1 ? $height - ($row * $cellHeight) : $cellHeight,
                ];
            }
        }

        return $regions;
    }
}
