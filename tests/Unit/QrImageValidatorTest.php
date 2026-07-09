<?php

namespace Tests\Unit;

use App\Services\QrCode\QrImageValidator;
use Tests\TestCase;

class QrImageValidatorTest extends TestCase
{
    public function test_rejects_non_image_binary(): void
    {
        $validator = new QrImageValidator();

        $this->assertFalse($validator->isValid('not-an-image'));
        $this->assertNull($validator->normalizeForStorage(str_repeat('x', 1024)));
    }

    public function test_accepts_png_magic_bytes(): void
    {
        $validator = new QrImageValidator();
        $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 32);

        $this->assertTrue($validator->isValid($png));
        $this->assertSame('image/png', $validator->mimeType($png));
        $this->assertSame('png', $validator->extension($png));

        $normalized = $validator->normalizeForStorage($png);
        $this->assertNotNull($normalized);
        $this->assertSame('image/png', $normalized['mime']);
    }

    public function test_accepts_jpeg_magic_bytes(): void
    {
        $validator = new QrImageValidator();
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat("\0", 32);

        $this->assertTrue($validator->isValid($jpeg));
        $this->assertSame('image/jpeg', $validator->mimeType($jpeg));
        $this->assertSame('jpg', $validator->extension($jpeg));
    }
}
