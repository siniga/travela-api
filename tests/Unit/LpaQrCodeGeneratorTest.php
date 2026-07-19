<?php

namespace Tests\Unit;

use App\Services\QrCode\LpaQrCodeGenerator;
use PHPUnit\Framework\TestCase;

class LpaQrCodeGeneratorTest extends TestCase
{
    public function test_normalize_lpa_payload_adds_prefix_when_missing(): void
    {
        $generator = new LpaQrCodeGenerator();

        $this->assertSame(
            'LPA:1$smdp.example.com$ACTIVATION-CODE',
            $generator->normalizeLpaPayload('smdp.example.com$ACTIVATION-CODE'),
        );
    }

    public function test_encode_value_preserves_https_links(): void
    {
        $generator = new LpaQrCodeGenerator();

        $this->assertSame(
            'https://esim.example.com/install',
            $generator->encodeValue('https://esim.example.com/install'),
        );
    }

    public function test_png_data_uri_is_generated_when_gd_is_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required to generate QR images.');
        }

        $generator = new LpaQrCodeGenerator();
        $dataUri = $generator->pngDataUri('LPA:1$smdp.example.com$UNIT-TEST');

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }
}
