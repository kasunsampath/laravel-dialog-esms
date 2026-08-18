<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Models;

use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Enums\Encoding;
use CodeRayTech\DialogEsms\Enums\MessageType;
use CodeRayTech\DialogEsms\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $reference
 * @property string $to
 * @property DeliveryStatus $status
 */
class SmsLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'message_type' => MessageType::class,
        'encoding' => Encoding::class,
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('dialog-esms.logging.log_table', 'dialog_sms_logs');
    }

    /**
     * The application model the message was sent to, if one was supplied.
     *
     * Polymorphic rather than a `user_id` foreign key: a package cannot assume
     * the host application has a users table, or that messages only ever go to
     * users.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(SmsWebhook::class, 'sms_log_id');
    }

    /**
     * The local campaign this send belongs to, if it came from one.
     *
     * Distinct from `dialog_campaign_id`, which is Dialog's own identifier for
     * a single accepted request and arrives later on a receipt.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /**
     * Find the log a delivery receipt belongs to.
     *
     * Correlation is by recipient, not by id. Dialog never sees the reference
     * this package generates, and the receipt carries their own `campaignId`
     * instead, so there is no shared identifier at send time. Matching on the
     * last nine digits makes every spelling of the number line up.
     *
     * Ties are broken by taking the most recent send, which is the best
     * available guess when the same number was messaged more than once.
     */
    public static function correlate(?string $campaignId, ?string $number): ?self
    {
        if ($campaignId !== null && $campaignId !== '') {
            $byCampaign = static::query()
                ->where('dialog_campaign_id', $campaignId)
                ->latest('id')
                ->first();

            if ($byCampaign) {
                return $byCampaign;
            }
        }

        $suffix = PhoneNumber::subscriberSuffix((string) $number);

        if ($suffix === null) {
            return null;
        }

        return static::query()
            ->where('to', 'like', '%' . $suffix . '%')
            ->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Pending->value])
            ->latest('id')
            ->first()
            ?? static::query()
                ->where('to', 'like', '%' . $suffix . '%')
                ->latest('id')
                ->first();
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::Delivered->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::Failed->value);
    }

    /**
     * Sends still awaiting a delivery receipt.
     */
    public function scopeAwaitingReceipt(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::Sent->value);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
