<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Events;

use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dialog rejected the campaign, or it never reached them.
 *
 * Listen for this to alert on billing failures: check
 * `$event->exception->isBillingIssue()` rather than string-matching messages.
 */
class SmsFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $recipient,
        public readonly string $message,
        public readonly DialogEsmsException $exception,
        public readonly ?int $logId = null,
    ) {}
}
