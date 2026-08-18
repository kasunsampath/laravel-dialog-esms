<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Unit;

use CodeRayTech\DialogEsms\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function test_it_normalizes_local_spellings(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function normalizationCases(): array
    {
        return [
            'already normalised' => ['94772345678', '94772345678'],
            'plus prefixed' => ['+94772345678', '94772345678'],
            'local trunk' => ['0772345678', '94772345678'],
            'international dial out' => ['0094772345678', '94772345678'],
            'spaces' => ['077 234 5678', '94772345678'],
            'dashes' => ['077-234-5678', '94772345678'],
            'parentheses' => ['(077) 234 5678', '94772345678'],
            'bare subscriber number' => ['772345678', '94772345678'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('validityCases')]
    public function test_it_validates_mobile_numbers(string $input, bool $expected): void
    {
        $this->assertSame($expected, PhoneNumber::isValid($input));
    }

    /** @return array<string, array{string, bool}> */
    public static function validityCases(): array
    {
        return [
            // Keyed by prefix rather than by operator: mobile prefixes get
            // reassigned between networks over time, and the validator only
            // cares that it is a 07x mobile rather than a landline.
            'mobile 077' => ['0772345678', true],
            'mobile 071' => ['0712345678', true],
            'mobile 070' => ['0701234567', true],
            'plus form' => ['+94771234567', true],
            'colombo landline' => ['0112345678', false],
            'too short' => ['07723456', false],
            'too long' => ['077234567833', false],
            'letters' => ['not a number', false],
            'empty' => ['', false],
        ];
    }

    public function test_it_rejects_landlines_because_esms_cannot_deliver_to_them(): void
    {
        // 011 is a Colombo landline. It normalises to a well-formed 94xxxxxxxxx
        // string, so only the mobile-prefix check catches it.
        $this->assertSame('94112345678', PhoneNumber::normalize('0112345678'));
        $this->assertFalse(PhoneNumber::isValid('0112345678'));
    }

    public function test_it_deduplicates_across_different_spellings(): void
    {
        $result = PhoneNumber::normalizeMany([
            '0772345678',
            '+94772345678',
            '94772345678',
            '0771234567',
        ]);

        $this->assertSame(['94772345678', '94771234567'], $result);
    }

    public function test_it_partitions_valid_and_invalid_numbers(): void
    {
        $result = PhoneNumber::partition([
            '0772345678',
            '0112345678',
            'garbage',
            '+94771234567',
        ]);

        $this->assertSame(['94772345678', '94771234567'], $result['valid']);
        $this->assertSame(['0112345678', 'garbage'], $result['invalid']);
    }

    public function test_subscriber_suffix_matches_across_spellings(): void
    {
        $expected = '772345678';

        $this->assertSame($expected, PhoneNumber::subscriberSuffix('94772345678'));
        $this->assertSame($expected, PhoneNumber::subscriberSuffix('+94772345678'));
        $this->assertSame($expected, PhoneNumber::subscriberSuffix('0772345678'));
    }

    public function test_subscriber_suffix_is_null_when_too_short_to_identify(): void
    {
        $this->assertNull(PhoneNumber::subscriberSuffix('12345'));
    }
}
