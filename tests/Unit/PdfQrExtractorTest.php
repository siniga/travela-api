<?php

namespace Tests\Unit;

use App\Services\QrCode\PdfPageRasterizer;
use App\Services\QrCode\PdfQrExtractor;
use App\Services\QrCode\QrImageDecoder;
use ReflectionClass;
use Tests\TestCase;

class PdfQrExtractorTest extends TestCase
{
    public function test_sort_images_by_decode_priority_prefers_larger_payloads(): void
    {
        $extractor = new PdfQrExtractor(new QrImageDecoder(), new PdfPageRasterizer());
        $ref = new ReflectionClass($extractor);
        $method = $ref->getMethod('sortImagesByDecodePriority');
        $method->setAccessible(true);

        $sorted = $method->invoke($extractor, [
            ['binary' => 'small', 'extension' => 'png'],
            ['binary' => str_repeat('x', 100), 'extension' => 'png'],
            ['binary' => 'medium-size', 'extension' => 'jpg'],
        ]);

        $this->assertSame(100, strlen($sorted[0]['binary']));
    }
}
