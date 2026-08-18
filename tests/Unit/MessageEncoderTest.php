<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Tests\Unit;

use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Enums\Encoding;
use KasunSampath\DialogEsms\Support\MessageEncoder;
use PHPUnit\Framework\TestCase;

class MessageEncoderTest extends TestCase
{
    public function test_plain_english_is_gsm7(): void
    {
        $this->assertSame(Encoding::Gsm7, MessageEncoder::detect('Your code is 4821'));
    }

    public function test_sinhala_forces_ucs2(): void
    {
        // Sinhala has no GSM-7 representation, so capacity drops 160 -> 70.
        $this->assertSame(Encoding::Ucs2, MessageEncoder::detect('ඔබගේ කේතය 4821'));
    }

    public function test_tamil_forces_ucs2(): void
    {
        $this->assertSame(Encoding::Ucs2, MessageEncoder::detect('உங்கள் குறியீடு 4821'));
    }

    public function test_a_single_emoji_forces_ucs2_for_the_whole_message(): void
    {
        // There is no partial fallback: one character re-encodes everything.
        $this->assertSame(Encoding::Gsm7, MessageEncoder::detect('Sale ends Friday'));
        $this->assertSame(Encoding::Ucs2, MessageEncoder::detect('Sale ends Friday 🎉'));
    }

    public function test_a_curly_quote_forces_ucs2(): void
    {
        // The classic one: copy-pasted from a word processor.
        $this->assertSame(Encoding::Ucs2, MessageEncoder::detect("Don\u{2019}t miss out"));
    }

    public function test_gsm7_boundary_at_160(): void
    {
        $this->assertSame(1, MessageEncoder::segments(str_repeat('a', 160)));
        $this->assertSame(2, MessageEncoder::segments(str_repeat('a', 161)));
    }

    public function test_gsm7_concatenated_parts_hold_153(): void
    {
        $this->assertSame(2, MessageEncoder::segments(str_repeat('a', 306)));
        $this->assertSame(3, MessageEncoder::segments(str_repeat('a', 307)));
    }

    public function test_ucs2_boundary_at_70(): void
    {
        $sinhala = str_repeat('ක', 70);
        $this->assertSame(Encoding::Ucs2, MessageEncoder::detect($sinhala));
        $this->assertSame(1, MessageEncoder::segments($sinhala));
        $this->assertSame(2, MessageEncoder::segments(str_repeat('ක', 71)));
    }

    public function test_ucs2_concatenated_parts_hold_67(): void
    {
        $this->assertSame(2, MessageEncoder::segments(str_repeat('ක', 134)));
        $this->assertSame(3, MessageEncoder::segments(str_repeat('ක', 135)));
    }

    public function test_extended_gsm_characters_cost_two_septets(): void
    {
        // A euro sign is reachable only through the escape sequence.
        $this->assertSame(Encoding::Gsm7, MessageEncoder::detect('€'));
        $this->assertSame(2, MessageEncoder::length('€'));
        $this->assertSame(2, MessageEncoder::length('{'));

        // 80 euro signs is 160 septets — still one message, but only just.
        $this->assertSame(1, MessageEncoder::segments(str_repeat('€', 80)));
        $this->assertSame(2, MessageEncoder::segments(str_repeat('€', 81)));
    }

    public function test_ucs2_length_counts_utf16_code_units_not_codepoints(): void
    {
        // An emoji outside the BMP is a surrogate pair and occupies two units,
        // so it eats two characters of a 70-character budget, not one.
        $this->assertSame(2, MessageEncoder::length('🎉'));
    }

    public function test_empty_message_has_no_segments(): void
    {
        $this->assertSame(0, MessageEncoder::segments(''));
    }

    public function test_it_reports_the_characters_that_forced_unicode(): void
    {
        $offenders = MessageEncoder::nonGsmCharacters("Don\u{2019}t miss out 🎉");

        $this->assertSame(["\u{2019}", '🎉'], $offenders);
    }

    public function test_it_reports_nothing_for_a_gsm7_message(): void
    {
        $this->assertSame([], MessageEncoder::nonGsmCharacters('Plain text'));
    }

    public function test_remaining_capacity_in_a_single_segment(): void
    {
        $this->assertSame(150, MessageEncoder::remainingInSegment('0123456789'));
    }

    public function test_remaining_capacity_once_concatenated(): void
    {
        // 200 chars spans 2 parts of 153 = 306 capacity.
        $this->assertSame(106, MessageEncoder::remainingInSegment(str_repeat('a', 200)));
    }

    // ------------------------------------------------------------- estimates

    public function test_estimate_multiplies_segments_by_recipients(): void
    {
        // The headline case: a 200-character Sinhala promo to 10,000 people.
        $estimate = MessageEstimate::for(str_repeat('ක', 200), 10_000);

        $this->assertSame(Encoding::Ucs2, $estimate->encoding);
        $this->assertSame(3, $estimate->segments);
        $this->assertSame(30_000, $estimate->billableMessages);
    }

    public function test_estimate_for_the_same_length_in_english(): void
    {
        $estimate = MessageEstimate::for(str_repeat('a', 200), 10_000);

        $this->assertSame(2, $estimate->segments);
        $this->assertSame(20_000, $estimate->billableMessages);
    }

    public function test_it_flags_a_message_that_is_only_accidentally_unicode(): void
    {
        // Latin text pushed to UCS-2 by two stray characters — fixable.
        $estimate = MessageEstimate::for("Don\u{2019}t miss out \u{2014} sale ends Friday");

        $this->assertTrue($estimate->isAccidentallyUnicode());
    }

    public function test_it_does_not_flag_genuine_sinhala_as_accidental(): void
    {
        // Nothing to fix here; UCS-2 is unavoidable.
        $estimate = MessageEstimate::for('ඔබගේ ඇණවුම තහවුරු කර ඇත');

        $this->assertFalse($estimate->isAccidentallyUnicode());
    }

    public function test_cost_multiplies_out_against_a_rate(): void
    {
        $estimate = MessageEstimate::for(str_repeat('a', 200), 1_000);

        $this->assertSame(2_000.0, $estimate->costAt(1.0));
        $this->assertEqualsWithDelta(500.0, $estimate->costAt(0.25), 0.001);
    }

    public function test_summary_reads_sensibly(): void
    {
        $summary = MessageEstimate::for('Hello', 1)->summary();

        $this->assertStringContainsString('GSM-7', $summary);
        $this->assertStringContainsString('1 billable message', $summary);
    }
}
