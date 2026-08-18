<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Support;

use KasunSampath\DialogEsms\Enums\Encoding;

/**
 * Works out how many SMS a piece of text will actually cost.
 *
 * The GSM 7-bit default alphabet (GSM 03.38) is the only character set that
 * fits 160 characters into one message. Anything outside it forces the whole
 * message to UCS-2, which holds 70. There is no partial fallback: one stray
 * character re-encodes the entire text.
 *
 * For Sri Lanka this is the difference between a campaign costing what you
 * budgeted and costing three times that, because neither Sinhala nor Tamil is
 * in the GSM alphabet.
 */
final class MessageEncoder
{
    /**
     * The GSM 03.38 default alphabet. Each of these occupies one septet.
     *
     * Note the characters that are *not* here and catch people out: curly
     * quotes, the en dash, the ellipsis character, and every emoji. Word
     * processors and CMS editors produce all of them by default.
     */
    private const GSM7_BASIC = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞ'
        . 'ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
        . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * Characters reachable only via the escape sequence, so they cost two
     * septets each. A message of 100 euro signs is 200 characters of budget.
     */
    private const GSM7_EXTENDED = "\f^{}\\[~]|€";

    /**
     * Which encoding this text will be sent as.
     */
    public static function detect(string $message): Encoding
    {
        $basic = self::charset(self::GSM7_BASIC);
        $extended = self::charset(self::GSM7_EXTENDED);

        foreach (self::characters($message) as $char) {
            if (! isset($basic[$char]) && ! isset($extended[$char])) {
                return Encoding::Ucs2;
            }
        }

        return Encoding::Gsm7;
    }

    /**
     * Billable length of the text, in the units of its encoding.
     *
     * For GSM-7 this counts escaped characters twice. For UCS-2 it counts
     * UTF-16 code units, not codepoints — an emoji outside the basic
     * multilingual plane is a surrogate pair and occupies two.
     */
    public static function length(string $message, ?Encoding $encoding = null): int
    {
        $encoding ??= self::detect($message);

        if ($encoding === Encoding::Ucs2) {
            return intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);
        }

        $extended = self::charset(self::GSM7_EXTENDED);
        $length = 0;

        foreach (self::characters($message) as $char) {
            $length += isset($extended[$char]) ? 2 : 1;
        }

        return $length;
    }

    /**
     * How many messages the text will be split into — and therefore billed as.
     */
    public static function segments(string $message, ?Encoding $encoding = null): int
    {
        $encoding ??= self::detect($message);
        $length = self::length($message, $encoding);

        if ($length === 0) {
            return 0;
        }

        if ($length <= $encoding->singleLimit()) {
            return 1;
        }

        return (int) ceil($length / $encoding->concatenatedLimit());
    }

    /**
     * Characters that could be dropped to get back to GSM-7.
     *
     * Usually a handful of smart quotes pasted from a document. Swapping them
     * for ASCII equivalents can cut a campaign's cost by more than half, so it
     * is worth surfacing rather than silently paying for UCS-2.
     *
     * @return array<int, string> Unique offending characters, in order of first appearance.
     */
    public static function nonGsmCharacters(string $message): array
    {
        $basic = self::charset(self::GSM7_BASIC);
        $extended = self::charset(self::GSM7_EXTENDED);
        $found = [];

        foreach (self::characters($message) as $char) {
            if (! isset($basic[$char]) && ! isset($extended[$char])) {
                $found[$char] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Remaining capacity before the next segment is charged for.
     */
    public static function remainingInSegment(string $message, ?Encoding $encoding = null): int
    {
        $encoding ??= self::detect($message);
        $length = self::length($message, $encoding);
        $segments = self::segments($message, $encoding);

        if ($segments <= 1) {
            return $encoding->singleLimit() - $length;
        }

        return ($segments * $encoding->concatenatedLimit()) - $length;
    }

    /**
     * @return array<int, string>
     */
    private static function characters(string $message): array
    {
        return mb_str_split($message, 1, 'UTF-8');
    }

    /**
     * Character list as a lookup map, built once per charset.
     *
     * @return array<string, true>
     */
    private static function charset(string $chars): array
    {
        /** @var array<string, array<string, true>> $cache */
        static $cache = [];

        return $cache[$chars] ??= array_fill_keys(mb_str_split($chars, 1, 'UTF-8'), true);
    }
}
