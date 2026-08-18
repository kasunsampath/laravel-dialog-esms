<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw delivery receipt from Dialog.
 *
 * Every callback is stored, including ones that cannot be parsed or matched to
 * a send. Dialog publishes no schema for this payload, so the archive of raw
 * callbacks is the only record of what the format actually is — and the only
 * way to notice when it changes.
 */
class SmsWebhook extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('dialog-esms.logging.webhook_table', 'dialog_sms_webhooks');
    }

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class, 'sms_log_id');
    }

    /**
     * Receipts that arrived but matched no known send.
     */
    public function scopeUncorrelated($query)
    {
        return $query->whereNull('sms_log_id');
    }
}
