<?php

namespace Tests\Unit;

use App\Services\Esim\QrCode\PdfPageRasterizer;
use App\Services\Esim\QrCode\PdfQrExtractor;
use App\Services\Esim\QrCode\QrImageCoercer;
use App\Services\Esim\QrCode\QrImageDecoder;
use App\Services\Esim\QrCode\QrImageValidator;
use App\Services\Esim\QrCode\QrRegionScanner;
use Tests\TestCase;

class PdfQrExtractorTest extends TestCase
{
    public function test_extractor_can_be_constructed_with_all_dependencies(): void
    {
        $validator = new QrImageValidator;
        $extractor = new PdfQrExtractor(
            new QrImageDecoder,
            new PdfPageRasterizer,
            $validator,
            new QrImageCoercer($validator),
            new QrRegionScanner,
        );

        $this->assertInstanceOf(PdfQrExtractor::class, $extractor);
    }
}
