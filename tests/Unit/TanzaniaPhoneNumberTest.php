<?php

namespace Tests\Unit;

use App\Support\TanzaniaPhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TanzaniaPhoneNumberTest extends TestCase
{
    public function test_normalizes_leading_zero_local_number(): void
    {
        $this->assertSame('255712345678', TanzaniaPhoneNumber::normalize('0712345678'));
    }

    public function test_normalizes_plus_country_code(): void
    {
        $this->assertSame('255712345678', TanzaniaPhoneNumber::normalize('+255712345678'));
    }

    public function test_keeps_already_normalized_number(): void
    {
        $this->assertSame('255712345678', TanzaniaPhoneNumber::normalize('255712345678'));
    }

    public function test_rejects_invalid_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TanzaniaPhoneNumber::normalize('12345');
    }
}
