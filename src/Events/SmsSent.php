<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Events;

use CodeRayTech\DialogEsms\Data\SmsResult;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dialog accepted the campaign. This is acceptance, not delivery.
 */
class SmsSent
{
    use Dispatchable;

    public function __construct(
        public readonly SmsResult $result,
        public readonly string $message,
    ) {}
}
