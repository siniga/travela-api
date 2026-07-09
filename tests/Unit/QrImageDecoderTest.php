<?php

namespace Tests\Unit;

use App\Services\QrCode\QrImageDecoder;
use Tests\TestCase;

class QrImageDecoderTest extends TestCase
{
    public function test_guess_image_extension_detects_png_and_jpeg(): void
    {
        $decoder = new QrImageDecoder();

        $this->assertSame('png', $decoder->guessImageExtension("\x89PNG\r\n\x1a\n"));
        $this->assertSame('jpg', $decoder->guessImageExtension("\xFF\xD8\xFF\xE0"));
        $this->assertSame('png', $decoder->guessImageExtension('unknown-binary'));
    }

    public function test_build_variants_deduplicates_identical_images(): void
    {
        $decoder = new QrImageDecoder();
        $binary = "\x89PNG\r\n\x1a\n".str_repeat('a', 32);

        $variants = $decoder->buildVariants($binary);

        $this->assertNotEmpty($variants);
        $this->assertSame($binary, $variants[0]['binary']);
        $this->assertCount(1, $variants);
    }
}
