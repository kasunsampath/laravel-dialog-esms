<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Support;

/**
 * Normalisation for Sri Lankan mobile numbers.
 *
 * Dialog expects recipients in bare international form — `94XXXXXXXXX`, no
 * plus sign, no separators. It does not reject other shapes with a useful
 * error; a malformed entry is simply dropped from the campaign, so a partly
 * bad list looks like a partial success with no indication of which numbers
 * were discarded. Normalise before sending, always.
 */
final class PhoneNumber
{
    /** Sri Lanka country calling code. */
    public const COUNTRY_CODE = '94';

    /**
     * Convert any common local spelling into `94XXXXXXXXX`.
     *
     * Accepts +94xxxxxxxxx, 0094xxxxxxxxx, 94xxxxxxxxx, 0xxxxxxxxx and the
     * bare 9-digit subscriber number. Returns the digits unchanged when the
     * shape is unrecognised so the caller can validate and report it, rather
     * than silently shipping a mangled number.
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        // 0094XXXXXXXXX — international prefix dialled out.
        if (str_starts_with($digits, '00' . self::COUNTRY_CODE)) {
            return substr($digits, 2);
        }

        // Already 94XXXXXXXXX.
        if (str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) === 11) {
            return $digits;
        }

        // Local trunk form 0XXXXXXXXX.
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return self::COUNTRY_CODE . substr($digits, 1);
        }

        // Bare subscriber number XXXXXXXXX, e.g. copied out of a spreadsheet
        // that stripped the leading zero.
        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return self::COUNTRY_CODE . $digits;
        }

        return $digits;
    }

    /**
     * Whether the number normalises to a valid Sri Lankan mobile.
     *
     * Mobile prefixes are 94 followed by 7 and eight further digits. Landlines
     * are rejected — Dialog eSMS cannot deliver to them.
     */
    public static function isValid(string $phone): bool
    {
        return (bool) preg_match('/^947\d{8}$/', self::normalize($phone));
    }

    /**
     * Normalise a list, dropping duplicates while preserving order.
     *
     * @param  iterable<string>  $numbers
     * @return array<int, string>
     */
    public static function normalizeMany(iterable $numbers): array
    {
        $out = [];

        foreach ($numbers as $number) {
            $normalized = self::normalize((string) $number);

            if ($normalized !== '') {
                $out[$normalized] = true;
            }
        }

        // array_keys casts numeric-string keys back to int — a normalised
        // number is all digits, so every key would come back as an integer and
        // break strict comparisons downstream.
        return array_map(strval(...), array_keys($out));
    }

    /**
     * Split a list into valid and invalid buckets.
     *
     * @param  iterable<string>  $numbers
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    public static function partition(iterable $numbers): array
    {
        $valid = [];
        $invalid = [];

        foreach ($numbers as $number) {
            $original = (string) $number;

            if (self::isValid($original)) {
                $valid[self::normalize($original)] = true;
            } else {
                $invalid[] = $original;
            }
        }

        // See normalizeMany: array_keys would return ints for digit-only keys.
        return ['valid' => array_map(strval(...), array_keys($valid)), 'invalid' => $invalid];
    }

    /**
     * The last nine digits, used to correlate delivery receipts.
     *
     * Receipts echo the recipient in whichever form Dialog holds it, which is
     * not necessarily the form that was submitted. Comparing the subscriber
     * portion makes 94xxxxxxxxx, 0xxxxxxxxx and +94xxxxxxxxx all line up.
     */
    public static function subscriberSuffix(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }
}
