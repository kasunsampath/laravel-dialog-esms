<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Models;

use CodeRayTech\DialogEsms\Enums\CampaignStatus;
use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Enums\Encoding;
use CodeRayTech\DialogEsms\Enums\MessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of sends, so a marketing run can be reported on as one thing.
 *
 * Delivery figures here come from receipts, not from the send response. Dialog
 * tells you only that a campaign was accepted; whether anything arrived is
 * knowable solely through the `push_notification_url` callback. If receipts
 * are not configured, `deliveryRate()` returns null rather than a misleading
 * zero — no data is not the same as no deliveries.
 */
class Campaign extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => CampaignStatus::class,
        'type' => MessageType::class,
        'encoding' => Encoding::class,
        'metadata' => 'array',
        'suppressed_numbers' => 'array',
        'invalid_numbers' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'scheduled_for' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('dialog-esms.logging.campaign_table', 'dialog_sms_campaigns');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'campaign_id');
    }

    /**
     * Recipients confirmed delivered by a receipt.
     */
    public function deliveredCount(): int
    {
        return $this->logs()->where('status', DeliveryStatus::Delivered->value)->count();
    }

    public function failedCount(): int
    {
        return $this->logs()->where('status', DeliveryStatus::Failed->value)->count();
    }

    /**
     * Share of accepted recipients confirmed delivered, 0.0 to 1.0.
     *
     * Null when nothing was accepted, or when no receipt has ever arrived for
     * this campaign — which usually means the push URL is not configured or is
     * unreachable, not that delivery failed.
     */
    public function deliveryRate(): ?float
    {
        if ($this->accepted_count === 0) {
            return null;
        }

        $withReceipts = $this->logs()
            ->whereIn('status', [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value])
            ->count();

        if ($withReceipts === 0) {
            return null;
        }

        return $this->deliveredCount() / $this->accepted_count;
    }

    /**
     * Sends still waiting on a receipt.
     */
    public function pendingReceiptCount(): int
    {
        return $this->logs()->where('status', DeliveryStatus::Sent->value)->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $rate = $this->deliveryRate();

        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'type' => $this->type->value,
            'encoding' => $this->encoding?->value,
            'segments_per_message' => $this->segments_per_message,
            'recipients' => $this->total_recipients,
            'accepted' => $this->accepted_count,
            'suppressed' => $this->suppressed_count,
            'invalid' => $this->invalid_count,
            'billable_messages' => $this->billable_messages,
            'delivered' => $this->deliveredCount(),
            'failed' => $this->failedCount(),
            'awaiting_receipt' => $this->pendingReceiptCount(),
            'delivery_rate' => $rate === null ? null : round($rate * 100, 1),
        ];
    }

    public function markSending(): void
    {
        if ($this->status === CampaignStatus::Queued) {
            $this->update(['status' => CampaignStatus::Sending, 'started_at' => now()]);
        }
    }

    /**
     * Close the campaign once every chunk has reported back.
     *
     * Failed only when nothing at all was accepted; a campaign where some
     * chunks landed is completed, with the failures visible in the logs.
     */
    public function markFinished(): void
    {
        $this->refresh();

        $this->update([
            'status' => $this->accepted_count > 0 ? CampaignStatus::Completed : CampaignStatus::Failed,
            'completed_at' => now(),
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => CampaignStatus::Cancelled, 'completed_at' => now()]);
    }

    public function isCancelled(): bool
    {
        return $this->status === CampaignStatus::Cancelled;
    }
}
