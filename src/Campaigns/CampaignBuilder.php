<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Campaigns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Enums\CampaignStatus;
use KasunSampath\DialogEsms\Enums\MessageType;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Jobs\SendCampaignChunk;
use KasunSampath\DialogEsms\Models\Campaign;
use KasunSampath\DialogEsms\Models\OptOut;
use KasunSampath\DialogEsms\Models\SmsTemplate;
use KasunSampath\DialogEsms\Support\PhoneNumber;
use KasunSampath\DialogEsms\Support\QuietHours;
use Illuminate\Support\Facades\Bus;

/**
 * Fluent builder for a queued campaign.
 *
 *     DialogEsms::campaign('October promo')
 *         ->message('Sale ends Friday')
 *         ->to($numbers)
 *         ->promotional()
 *         ->dispatch();
 *
 * Recipients are filtered in a fixed order — deduplicate, drop invalid
 * numbers, then remove opt-outs — and the counts from each stage are stored on
 * the campaign. That ordering matters for the audit trail: a number that is
 * both malformed and opted out is reported as invalid, which is the fact you
 * can act on.
 */
class CampaignBuilder
{
    /** @var array<int, string> */
    protected array $recipients = [];

    protected string $body = '';

    protected MessageType $type = MessageType::Promotional;

    protected ?string $senderId = null;

    protected ?string $scope = null;

    protected ?CarbonInterface $scheduledFor = null;

    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;

    protected bool $ignoreQuietHours = false;

    public function __construct(protected readonly string $name) {}

    public function message(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Use a stored template, rendered with the given values.
     *
     * @param  array<string, string|int|float>  $values
     */
    public function template(string $name, array $values = []): static
    {
        $template = SmsTemplate::named($name);

        $this->body = $template->render($values);
        $this->type = $template->type;

        return $this;
    }

    /**
     * @param  iterable<string>  $numbers
     */
    public function to(iterable $numbers): static
    {
        foreach ($numbers as $number) {
            $this->recipients[] = (string) $number;
        }

        return $this;
    }

    public function promotional(?string $scope = null): static
    {
        $this->type = MessageType::Promotional;
        $this->scope = $scope;

        return $this;
    }

    /**
     * Mark the campaign transactional, exempting it from opt-out filtering and
     * quiet hours.
     *
     * Only legitimate for messages the recipient's own action requires — OTPs,
     * receipts, delivery alerts. Using it to push marketing past a suppression
     * list is a compliance problem, and the reason this method is named
     * explicitly rather than being a flag.
     */
    public function transactional(): static
    {
        $this->type = MessageType::Transactional;

        return $this;
    }

    public function from(string $senderId): static
    {
        $this->senderId = $senderId;

        return $this;
    }

    public function scheduleFor(CarbonInterface $moment): static
    {
        $this->scheduledFor = $moment;

        return $this;
    }

    /**
     * Send even inside the quiet-hours window.
     *
     * For the rare promotional message that is genuinely time-critical. Not a
     * default, and worth a comment at the call site.
     */
    public function ignoringQuietHours(): static
    {
        $this->ignoreQuietHours = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Cost this campaign without creating or sending anything.
     *
     * Runs the same filtering as `dispatch()`, so the recipient count reflects
     * suppression and invalid numbers rather than the raw list you passed in.
     */
    public function estimate(): MessageEstimate
    {
        $this->assertSendable();

        return MessageEstimate::for($this->body, count($this->resolveRecipients()['allowed']));
    }

    /**
     * Persist the campaign in draft, without queueing anything.
     */
    public function create(): Campaign
    {
        $this->assertSendable();

        $resolved = $this->resolveRecipients();
        $estimate = MessageEstimate::for($this->body, count($resolved['allowed']));

        return Campaign::create([
            'name' => $this->name,
            'message' => $this->body,
            'sender_id' => $this->senderId ?? config('dialog-esms.sender_id'),
            'type' => $this->type,
            'status' => CampaignStatus::Draft,
            'encoding' => $estimate->encoding,
            'segments_per_message' => $estimate->segments,
            'billable_messages' => $estimate->billableMessages,
            'total_recipients' => count($resolved['allowed']) + count($resolved['suppressed']) + count($resolved['invalid']),
            'accepted_count' => 0,
            'suppressed_count' => count($resolved['suppressed']),
            'invalid_count' => count($resolved['invalid']),
            'suppressed_numbers' => $resolved['suppressed'],
            'invalid_numbers' => $resolved['invalid'],
            'metadata' => $this->metadata,
            'scheduled_for' => $this->forStorage($this->scheduledFor),
        ]);
    }

    /**
     * Convert a moment into the application timezone before it is stored.
     *
     * Quiet hours are reasoned about in the promotional timezone (Asia/Colombo
     * by default), but Eloquent's datetime cast writes whatever timezone the
     * instance carries as a naive string and reads it back as the application
     * timezone. Storing 08:00 Colombo directly therefore reads back as 08:00
     * UTC — 13:30 Colombo, five and a half hours late.
     *
     * The queue delay is unaffected either way, since Carbon knows its own
     * offset; it is the persisted column that silently lies.
     */
    protected function forStorage(?CarbonInterface $moment): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        return CarbonImmutable::instance($moment)
            ->setTimezone(config('app.timezone') ?: 'UTC');
    }

    /**
     * Create the campaign and queue its chunks.
     *
     * Chunks go out as separate jobs so a large run survives a worker restart
     * and can be rate limited. Nothing is sent synchronously — a
     * 50,000-recipient campaign would otherwise hold a request open for
     * minutes and lose its place if it died halfway.
     */
    public function dispatch(): Campaign
    {
        $campaign = $this->create();
        $resolved = $this->resolveRecipients();

        if ($resolved['allowed'] === []) {
            $campaign->update([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
            ]);

            return $campaign;
        }

        $delay = $this->resolveDelay();

        $campaign->update([
            'status' => CampaignStatus::Queued,
            'scheduled_for' => $this->forStorage($delay),
        ]);

        $chunkSize = max(1, (int) config('dialog-esms.chunk_size', 100));
        $chunks = array_chunk($resolved['allowed'], $chunkSize);
        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $job = new SendCampaignChunk(
                campaignId: $campaign->id,
                recipients: $chunk,
                isFinalChunk: $index === $lastIndex,
            );

            if ($delay !== null) {
                $job->delay($delay);
            }

            Bus::dispatch($job->onQueue((string) config('dialog-esms.queue.name', 'default')));
        }

        return $campaign;
    }

    /**
     * When the first chunk may run.
     *
     * An explicit schedule wins. Otherwise a promotional campaign built during
     * quiet hours is pushed to the next permitted moment rather than being
     * sent at 3am or silently dropped.
     */
    protected function resolveDelay(): ?CarbonInterface
    {
        if ($this->scheduledFor !== null) {
            return $this->scheduledFor;
        }

        if ($this->ignoreQuietHours || ! $this->type->respectsQuietHours()) {
            return null;
        }

        $quietHours = QuietHours::fromConfig();

        return $quietHours->isQuietAt() ? $quietHours->nextPermittedAfter() : null;
    }

    /**
     * Deduplicate, validate, then suppress.
     *
     * @return array{allowed: array<int, string>, suppressed: array<int, string>, invalid: array<int, string>}
     */
    protected function resolveRecipients(): array
    {
        $partitioned = PhoneNumber::partition($this->recipients);

        if (! $this->type->respectsOptOut()) {
            return [
                'allowed' => $partitioned['valid'],
                'suppressed' => [],
                'invalid' => $partitioned['invalid'],
            ];
        }

        $filtered = OptOut::filter($partitioned['valid'], $this->scope);

        return [
            'allowed' => $filtered['allowed'],
            'suppressed' => $filtered['suppressed'],
            'invalid' => $partitioned['invalid'],
        ];
    }

    /**
     * @throws DialogEsmsException
     */
    protected function assertSendable(): void
    {
        if (trim($this->body) === '') {
            throw new DialogEsmsException('Campaign has no message body.');
        }

        if ($this->recipients === []) {
            throw new DialogEsmsException('Campaign has no recipients.');
        }
    }
}
