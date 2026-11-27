<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Identity\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function numbers(): array
    {
        return [
            'local spaced' => ['082 123 4567', '+27821234567'],
            'local' => ['0821234567', '+27821234567'],
            'international' => ['+27 82 123 4567', '+27821234567'],
            'country code no plus' => ['27821234567', '+27821234567'],
            'double zero' => ['0027821234567', '+27821234567'],
            'foreign' => ['+263 77 123 4567', '+263771234567'],
            'too short' => ['082 123', null],
            'SA too long' => ['08212345678', null],
            'letters' => ['call me', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalises_to_e164(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalise($input));
    }

    public function test_masks_for_display(): void
    {
        $this->assertSame('+27 •• ••• 4567', PhoneNumber::mask('+27821234567'));
    }
}
