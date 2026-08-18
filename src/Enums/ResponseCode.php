<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Enums;

/**
 * Response codes returned by the Dialog eSMS `message-via-url` API.
 *
 * The API answers with a bare status code as the *response body* — not JSON,
 * not an HTTP status. A successful send returns the two characters `1`, and
 * the HTTP status is 200 regardless of outcome. Checking `$response->ok()`
 * therefore tells you nothing; you must read the body.
 *
 * This table is transcribed from live behaviour against the production
 * endpoint. Dialog publishes no public reference for it, but an independent
 * implementation — github.com/MaleeshaUdan/dialog-esms — arrived at an
 * identical table, which is the strongest corroboration available here.
 *
 * Two codes are routinely mistaken for each other and are worth committing to
 * memory:
 *
 *   - 2007 is an *invalid key*. It is returned not only for a wrong
 *     `esmsqk` value but also when a required parameter is misspelled, so a
 *     typo in `list` or `source_address` surfaces here and sends you hunting
 *     for a credentials problem that does not exist.
 *   - 2008 is the actual out-of-credit code.
 *
 * Do not edit these descriptions without a reproducible observation.
 */
enum ResponseCode: string
{
    case Success = '1';
    case CampaignCreationError = '2001';
    case BadRequest = '2002';
    case EmptyNumberList = '2003';
    case EmptyMessageBody = '2004';
    case InvalidNumberListFormat = '2005';
    case GetRequestsNotPermitted = '2006';
    case InvalidKey = '2007';
    case InsufficientBalance = '2008';
    case NoValidNumbers = '2009';
    case PackagingNotPermitted = '2010';
    case TransactionalError = '2011';

    /**
     * Human-readable explanation of the code.
     */
    public function message(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::CampaignCreationError => 'Error occurred during campaign creation',
            self::BadRequest => 'Bad request',
            self::EmptyNumberList => 'Empty number list',
            self::EmptyMessageBody => 'Empty message body',
            self::InvalidNumberListFormat => 'Invalid number list format',
            self::GetRequestsNotPermitted => 'Not eligible to send messages via GET requests (the account administrator has not granted this access level)',
            self::InvalidKey => 'Invalid key — the esmsqk parameter is wrong, or a required parameter name is misspelled',
            self::InsufficientBalance => 'Not enough money in the wallet, or no messages left in the package',
            self::NoValidNumbers => 'No valid numbers remained after removing mask-blocked numbers',
            self::PackagingNotPermitted => 'Not eligible to consume packaging',
            self::TransactionalError => 'Transactional error',
        };
    }

    /**
     * Whether retrying the identical request could plausibly succeed.
     *
     * Rejections caused by the request itself (bad key, empty body, malformed
     * numbers) will fail identically forever, so a retry only burns time. A
     * transient transactional error is worth another attempt.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TransactionalError, self::CampaignCreationError => true,
            default => false,
        };
    }

    /**
     * Whether the failure is a billing problem rather than a bug.
     */
    public function isBillingIssue(): bool
    {
        return $this === self::InsufficientBalance || $this === self::PackagingNotPermitted;
    }

    /**
     * Resolve a raw response body into a case, tolerating whitespace.
     *
     * Returns null for anything unrecognised rather than guessing — an unknown
     * code must not be reported as a success or as a specific failure.
     */
    public static function fromResponse(string $body): ?self
    {
        return self::tryFrom(trim($body));
    }

    /**
     * Describe a raw body even when it is not a code we know.
     */
    public static function describe(string $body): string
    {
        $body = trim($body);

        return self::tryFrom($body)?->message()
            ?? sprintf('Unknown Dialog response (code: %s)', $body === '' ? '<empty>' : $body);
    }
}
