<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PdfPageRasterizer
{
    /**
     * Render the first page of a PDF to PNG images at several DPI settings.
     *
     * @return list<array{binary: string, extension: string}>
     */
    public function rasterizeFirstPageVariants(string $pdfPath): array
    {
        if (! is_file($pdfPath)) {
            return [];
        }

        $variants = [];
        $seen = [];

        foreach ([200, 300, 400] as $dpi) {
            $raster = $this->rasterizeAtDpi($pdfPath, $dpi);
            if ($raster === null) {
                continue;
            }

            $hash = md5($raster['binary']);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $variants[] = $raster;
        }

        if ($variants === []) {
            Log::debug('eSIM PDF rasterization unavailable', ['pdf' => basename($pdfPath)]);
        }

        return $variants;
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    public function rasterizeFirstPage(string $pdfPath): ?array
    {
        $variants = $this->rasterizeFirstPageVariants($pdfPath);

        return $variants[0] ?? null;
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    private function rasterizeAtDpi(string $pdfPath, int $dpi): ?array
    {
        $viaImagick = $this->viaImagick($pdfPath, $dpi);
        if ($viaImagick !== null) {
            Log::debug('eSIM PDF rasterized via Imagick', [
                'pdf' => basename($pdfPath),
                'dpi' => $dpi,
            ]);

            return $viaImagick;
        }

        $viaGhostscript = $this->viaGhostscript($pdfPath, $dpi);
        if ($viaGhostscript !== null) {
            Log::debug('eSIM PDF rasterized via Ghostscript', [
                'pdf' => basename($pdfPath),
                'dpi' => $dpi,
            ]);

            return $viaGhostscript;
        }

        return null;
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    private function viaImagick(string $pdfPath, int $dpi = 300): ?array
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution($dpi, $dpi);
            $imagick->readImage($pdfPath.'[0]');
            $imagick->setImageFormat('png');
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $binary = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            if (! is_string($binary) || $binary === '') {
                return null;
            }

            return ['binary' => $binary, 'extension' => 'png'];
        } catch (\Throwable $e) {
            Log::debug('eSIM Imagick PDF rasterization failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    private function viaGhostscript(string $pdfPath, int $dpi = 300): ?array
    {
        $binary = $this->findGhostscriptBinary();
        if ($binary === null) {
            return null;
        }

        $outputPath = storage_path('app/private/esims/tmp/'.Str::uuid().'.png');
        @mkdir(dirname($outputPath), 0755, true);

        $command = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r%d -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s',
            escapeshellarg($binary),
            $dpi,
            escapeshellarg($outputPath),
            escapeshellarg($pdfPath),
        );

        try {
            exec($command.' 2>&1', $output, $exitCode);

            if ($exitCode !== 0 || ! is_file($outputPath)) {
                Log::debug('eSIM Ghostscript PDF rasterization failed', [
                    'exit_code' => $exitCode,
                    'output' => implode("\n", $output),
                ]);

                return null;
            }

            $png = file_get_contents($outputPath);

            return is_string($png) && $png !== ''
                ? ['binary' => $png, 'extension' => 'png']
                : null;
        } finally {
            @unlink($outputPath);
        }
    }

    private function findGhostscriptBinary(): ?string
    {
        $configured = config('services.ghostscript.binary');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = ['gswin64c', 'gswin32c', 'gs'];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolveExecutable($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach ($this->windowsGhostscriptPaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveExecutable(string $command): ?string
    {
        $checker = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $output = [];
        exec($checker.' '.escapeshellarg($command).' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $path = trim($output[0]);

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * @return list<string>
     */
    private function windowsGhostscriptPaths(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $paths = [];
        $prefixes = [
            getenv('ProgramFiles') ?: 'C:\\Program Files',
            getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
        ];

        foreach ($prefixes as $prefix) {
            $root = $prefix.'\\gs';
            if (! is_dir($root)) {
                continue;
            }

            $versions = glob($root.'\\*\\bin\\gswin64c.exe') ?: [];
            $paths = array_merge($paths, $versions);

            $versions = glob($root.'\\*\\bin\\gswin32c.exe') ?: [];
            $paths = array_merge($paths, $versions);
        }

        rsort($paths);

        return $paths;
    }
}
