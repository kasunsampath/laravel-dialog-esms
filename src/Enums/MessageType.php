<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Enums;

/**
 * What a message is for, which decides which safety rules apply to it.
 *
 * The distinction exists so marketing rules can never block a login. An OTP
 * that is silently suppressed because the recipient once unsubscribed from
 * promotions is a support incident and, for the user, a lockout — so
 * transactional messages deliberately bypass both the opt-out list and quiet
 * hours.
 *
 * The trade-off is that `Transactional` must never be used to dodge those
 * rules for marketing. Sending a promotion as transactional is a compliance
 * problem, not a clever workaround.
 */
enum MessageType: string
{
    /** OTPs, receipts, alerts the recipient asked for by acting. */
    case Transactional = 'transactional';

    /** Anything promotional. Subject to opt-out and quiet hours. */
    case Promotional = 'promotional';

    /**
     * Whether the opt-out list is consulted before sending.
     */
    public function respectsOptOut(): bool
    {
        return $this === self::Promotional;
    }

    /**
     * Whether sending is deferred outside permitted hours.
     */
    public function respectsQuietHours(): bool
    {
        return $this === self::Promotional;
    }
}
