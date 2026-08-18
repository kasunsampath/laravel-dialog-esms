<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Enums;

/**
 * The wire encoding an SMS will use, which determines how many messages you
 * are actually billed for.
 *
 * This matters more in Sri Lanka than almost anywhere else. Sinhala
 * (U+0D80–U+0DFF) and Tamil (U+0B80–U+0BFF) have no representation in the
 * GSM 7-bit alphabet, so any message containing them is sent as UCS-2 and the
 * per-message capacity drops from 160 characters to 70. A 200-character
 * Sinhala promotion is three messages, not one, and nothing in the API
 * response tells you that happened.
 *
 * A single emoji, curly quote or en dash does the same thing to an otherwise
 * English message.
 */
enum Encoding: string
{
    case Gsm7 = 'gsm-7';
    case Ucs2 = 'ucs-2';

    /**
     * Characters that fit in one message when the text is not split.
     */
    public function singleLimit(): int
    {
        return match ($this) {
            self::Gsm7 => 160,
            self::Ucs2 => 70,
        };
    }

    /**
     * Characters per part once a message is long enough to be concatenated.
     *
     * Lower than the single-message limit because each part gives up room to
     * a user-data header that tells the handset how to reassemble them.
     */
    public function concatenatedLimit(): int
    {
        return match ($this) {
            self::Gsm7 => 153,
            self::Ucs2 => 67,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Gsm7 => 'GSM-7',
            self::Ucs2 => 'UCS-2 (Unicode)',
        };
    }
}
