<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Events;

use CodeRayTech\DialogEsms\Models\SmsLog;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A delivery receipt confirmed the handset received the message.
 */
class SmsDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly SmsLog $log,
        public readonly string $msisdn,
    ) {}
}
