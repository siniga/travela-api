<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\EsimController;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

class AdminEsimQrPathTest extends TestCase
{
    public function test_locate_qr_image_finds_legacy_storage_path_and_migrates_file(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $relativePath = 'esims/qr-codes/255798091321.png';
        $legacyDir = storage_path('app/esims/qr-codes');
        if (! is_dir($legacyDir)) {
            mkdir($legacyDir, 0755, true);
        }

        $legacyFullPath = storage_path('app/'.$relativePath);
        file_put_contents($legacyFullPath, 'legacy-qr-binary');

        $controller = new EsimController();
        $ref = new ReflectionClass($controller);
        $locate = $ref->getMethod('locateQrImage');
        $locate->setAccessible(true);

        $found = $locate->invoke($controller, $relativePath);

        $this->assertSame(['disk' => 'local', 'path' => $relativePath], $found);
        $this->assertTrue(Storage::disk('local')->exists($relativePath));
        $this->assertSame('legacy-qr-binary', Storage::disk('local')->get($relativePath));
        $this->assertFileDoesNotExist($legacyFullPath);

        @unlink(storage_path('app/esims/qr-codes/.gitignore'));
    }

    public function test_normalize_qr_code_path_strips_storage_prefixes(): void
    {
        $controller = new EsimController();
        $ref = new ReflectionClass($controller);
        $normalize = $ref->getMethod('normalizeQrCodePath');
        $normalize->setAccessible(true);

        $this->assertSame(
            'esims/qr-codes/255798091321.png',
            $normalize->invoke($controller, 'private/esims/qr-codes/255798091321.png'),
        );

        $this->assertSame(
            'esims/qr-codes/255798091321.png',
            $normalize->invoke(
                $controller,
                storage_path('app/private/esims/qr-codes/255798091321.png'),
            ),
        );

        $this->assertSame(
            'esims/qr-codes/255798091321.png',
            $normalize->invoke($controller, 'storage/esims/qr-codes/255798091321.png'),
        );

        $this->assertSame(
            'esims/qr-codes/255798091321.png',
            $normalize->invoke(
                $controller,
                'https://api.thetravela.com/storage/esims/qr-codes/255798091321.png',
            ),
        );
    }
}
