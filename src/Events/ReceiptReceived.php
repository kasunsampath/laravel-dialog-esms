<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Events;

use CodeRayTech\DialogEsms\Models\SmsWebhook;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A delivery receipt arrived, correlated or not.
 *
 * Fired for every callback including unparseable ones, so listeners can alert
 * on a change in Dialog's payload format — which is the failure mode that
 * silently breaks delivery tracking.
 */
class ReceiptReceived
{
    use Dispatchable;

    public function __construct(
        public readonly SmsWebhook $webhook,
        public readonly bool $correlated,
    ) {}
}
