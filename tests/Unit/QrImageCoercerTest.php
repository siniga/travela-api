<?php

namespace Tests\Unit;

use App\Services\QrCode\QrImageCoercer;
use App\Services\QrCode\QrImageValidator;
use Tests\TestCase;

class QrImageCoercerTest extends TestCase
{
    public function test_candidates_include_raw_and_extension_fallbacks_for_unknown_binary(): void
    {
        $coercer = new QrImageCoercer(new QrImageValidator());
        $raw = str_repeat("\x00", 64);

        $candidates = $coercer->candidates($raw);

        $this->assertGreaterThanOrEqual(2, count($candidates));
    }

    public function test_candidates_normalize_valid_png_once(): void
    {
        $coercer = new QrImageCoercer(new QrImageValidator());
        $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 32);

        $candidates = $coercer->candidates($png);

        $this->assertCount(1, $candidates);
        $this->assertSame('png', $candidates[0]['extension']);
    }
}
