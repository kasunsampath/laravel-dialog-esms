<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Enums;

enum CampaignStatus: string
{
    /** Built and costed, nothing queued. */
    case Draft = 'draft';

    /** Chunks are on the queue. */
    case Queued = 'queued';

    /** At least one chunk has been handed to Dialog. */
    case Sending = 'sending';

    /** Every chunk finished; some may have been rejected. */
    case Completed = 'completed';

    /** Every chunk was rejected. */
    case Failed = 'failed';

    /** Stopped by an operator; remaining chunks will not send. */
    case Cancelled = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
