<?php

namespace Tests\Unit;

use App\Services\QrCode\PdfPageRasterizer;
use App\Services\QrCode\PdfQrExtractor;
use App\Services\QrCode\QrImageCoercer;
use App\Services\QrCode\QrImageDecoder;
use App\Services\QrCode\QrImageValidator;
use App\Services\QrCode\QrRegionScanner;
use Tests\TestCase;

class PdfQrExtractorTest extends TestCase
{
    public function test_extractor_can_be_constructed_with_all_dependencies(): void
    {
        $validator = new QrImageValidator();
        $extractor = new PdfQrExtractor(
            new QrImageDecoder(),
            new PdfPageRasterizer(),
            $validator,
            new QrImageCoercer($validator),
            new QrRegionScanner(),
        );

        $this->assertInstanceOf(PdfQrExtractor::class, $extractor);
    }
}
