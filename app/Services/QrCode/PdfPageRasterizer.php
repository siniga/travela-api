<?php

namespace App\Services\QrCode;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PdfPageRasterizer
{
    /**
     * Render the first page of a PDF to a PNG image.
     *
     * @return array{binary: string, extension: string}|null
     */
    public function rasterizeFirstPage(string $pdfPath): ?array
    {
        if (! is_file($pdfPath)) {
            return null;
        }

        $viaImagick = $this->viaImagick($pdfPath);
        if ($viaImagick !== null) {
            Log::debug('eSIM PDF rasterized via Imagick', ['pdf' => basename($pdfPath)]);

            return $viaImagick;
        }

        $viaGhostscript = $this->viaGhostscript($pdfPath);
        if ($viaGhostscript !== null) {
            Log::debug('eSIM PDF rasterized via Ghostscript', ['pdf' => basename($pdfPath)]);

            return $viaGhostscript;
        }

        Log::debug('eSIM PDF rasterization unavailable', ['pdf' => basename($pdfPath)]);

        return null;
    }

    /**
     * @return array{binary: string, extension: string}|null
     */
    private function viaImagick(string $pdfPath): ?array
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(300, 300);
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
    private function viaGhostscript(string $pdfPath): ?array
    {
        $binary = $this->findGhostscriptBinary();
        if ($binary === null) {
            return null;
        }

        $outputPath = storage_path('app/private/esims/tmp/'.Str::uuid().'.png');
        @mkdir(dirname($outputPath), 0755, true);

        $command = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r300 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s',
            escapeshellarg($binary),
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
