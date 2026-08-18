<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Jobs;

use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Enums\CampaignStatus;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends one chunk of a campaign.
 *
 * One job per chunk rather than one per campaign, so a large run is
 * interruptible and resumable: a worker restart loses at most one chunk, and
 * chunks already sent are not re-sent when the queue recovers. Re-sending an
 * SMS is not free and not invisible to the recipient, which is why the retry
 * policy below is deliberately narrow.
 */
class SendCampaignChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Low, on purpose. Every retry is another real send to the same people if
     * the failure happened after Dialog accepted the campaign, so this job
     * only retries failures it knows are safe to repeat.
     */
    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<int, string>  $recipients
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $recipients,
        public readonly bool $isFinalChunk = false,
    ) {}

    public function handle(SmsGateway $gateway): void
    {
        $campaign = Campaign::find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        // An operator cancelling mid-run must stop the chunks that have not
        // gone out yet; jobs already queued check here rather than being
        // hunted down and deleted from the queue.
        if ($campaign->isCancelled()) {
            return;
        }

        if (! $this->clearedRateLimit($campaign)) {
            return;
        }

        $campaign->markSending();

        try {
            $result = $gateway->sendBulk($this->recipients, $campaign->message, [
                'sender_id' => $campaign->sender_id,
                'campaign_id' => $campaign->id,
                'message_type' => $campaign->type,
                'suppress_opt_out_filter' => true,
            ]);

            $campaign->increment('accepted_count', $result->acceptedCount());
        } catch (DialogEsmsException $e) {
            Log::error('Dialog eSMS campaign chunk failed', [
                'campaign_id' => $campaign->id,
                'recipients' => count($this->recipients),
                'code' => $e->rawResponse,
                'error' => $e->getMessage(),
            ]);

            // A permanent rejection — bad key, empty wallet, unregistered mask
            // — will fail identically on every retry and on every remaining
            // chunk. Stop the whole campaign rather than grinding through
            // thousands of jobs that cannot succeed.
            if (! $e->isRetryable()) {
                $this->haltCampaign($campaign, $e);
                $this->fail($e);

                return;
            }

            throw $e;
        }

        if ($this->isFinalChunk) {
            $campaign->markFinished();
        }
    }

    /**
     * Hold the chunk back when the send rate would be exceeded.
     *
     * Releasing the job rather than sleeping keeps the worker free, which
     * matters when a campaign has hundreds of chunks queued behind it.
     */
    protected function clearedRateLimit(Campaign $campaign): bool
    {
        $perMinute = (int) config('dialog-esms.queue.rate_limit_per_minute', 0);

        if ($perMinute <= 0) {
            return true;
        }

        // Positional arguments deliberately: this package spans three Laravel
        // majors, and named arguments bind to parameter names, which are not
        // part of a framework's public API contract the way the signature
        // order is.
        $allowed = RateLimiter::attempt(
            'dialog-esms:send',
            $perMinute,
            static fn (): bool => true,
            60,
        );

        if ($allowed === false) {
            $this->release(RateLimiter::availableIn('dialog-esms:send') ?: 10);

            return false;
        }

        return true;
    }

    protected function haltCampaign(Campaign $campaign, DialogEsmsException $e): void
    {
        $campaign->update([
            'status' => $campaign->accepted_count > 0 ? CampaignStatus::Completed : CampaignStatus::Failed,
            'completed_at' => now(),
            'metadata' => array_merge($campaign->metadata ?? [], [
                'halted_reason' => $e->getMessage(),
                'halted_code' => $e->rawResponse,
            ]),
        ]);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['dialog-esms', 'campaign:' . $this->campaignId];
    }
}
