<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Enums;

/**
 * Normalised lifecycle status for a single message.
 */
enum DeliveryStatus: string
{
    /** Queued locally, not yet handed to Dialog. */
    case Pending = 'pending';

    /** Dialog accepted the campaign. No delivery confirmation yet. */
    case Sent = 'sent';

    /** A delivery receipt confirmed handset delivery. */
    case Delivered = 'delivered';

    /** Dialog rejected the send, or a receipt reported non-delivery. */
    case Failed = 'failed';

    /**
     * Map a value from a Dialog delivery receipt onto a lifecycle status.
     *
     * Unrecognised values deliberately resolve to `Sent` — that is what we
     * already believed before the receipt arrived. Dialog documents no list of
     * receipt statuses, so treating an unknown token as a delivery would
     * fabricate a confirmation, and treating it as a failure would raise false
     * alarms on messages that did arrive.
     *
     * Only `status=1` has been observed in the wild (meaning delivered).
     */
    public static function fromReceipt(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'delivered', 'delivrd', 'success', 'succeeded', '1' => self::Delivered,
            'sent', 'submitted', 'pending', 'accepted', 'acceptd' => self::Sent,
            'failed', 'rejected', 'rejectd', 'expired', 'undeliv', 'error', '0' => self::Failed,
            default => self::Sent,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
