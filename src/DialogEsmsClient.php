<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms;

use KasunSampath\DialogEsms\Campaigns\CampaignBuilder;
use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Data\Balance;
use KasunSampath\DialogEsms\Data\BulkResult;
use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Data\SmsResult;
use KasunSampath\DialogEsms\Enums\DeliveryStatus;
use KasunSampath\DialogEsms\Enums\MessageType;
use KasunSampath\DialogEsms\Enums\ResponseCode;
use KasunSampath\DialogEsms\Events\SmsFailed;
use KasunSampath\DialogEsms\Events\SmsSent;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Models\OptOut;
use KasunSampath\DialogEsms\Models\SmsLog;
use KasunSampath\DialogEsms\Support\MessageEncoder;
use KasunSampath\DialogEsms\Support\PhoneNumber;
use KasunSampath\DialogEsms\Support\QuietHours;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Client for the Dialog eSMS `message-via-url` API.
 *
 * Three things about this API drive the shape of the code below, and all three
 * are undocumented by Dialog:
 *
 *  1. **Every response is HTTP 200.** Success and failure alike. The outcome is
 *     the response *body*, a bare status code such as `1` or `2007`. Any code
 *     that branches on `$response->successful()` will treat every rejection as
 *     a send.
 *  2. **Parameter names are exact and unforgiving.** They are `esmsqk`, `list`,
 *     `message` and `source_address`. Misspell any of them and the API answers
 *     `2007`, which reads as "invalid key" and sends you to check credentials
 *     that were never the problem.
 *  3. **Nothing identifies the message.** The send response contains no id, so
 *     correlating a later delivery receipt has to fall back on the recipient's
 *     number.
 */
class DialogEsmsClient implements SmsGateway
{
    /** Sender mask overriding the configured default for the next send. */
    protected ?string $senderOverride = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly array $config,
        protected readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Use a different registered sender mask for the next send.
     */
    public function usingSender(string $senderId): static
    {
        $clone = clone $this;
        $clone->senderOverride = $senderId;

        return $clone;
    }

    public function send(string $to, string $message, array $options = []): SmsResult
    {
        if (! $this->enabled()) {
            return SmsResult::skipped($to, 'Dialog eSMS is disabled by configuration');
        }

        if (! PhoneNumber::isValid($to)) {
            throw DialogEsmsException::invalidRecipient($to);
        }

        $recipient = PhoneNumber::normalize($to);
        $messageType = $this->resolveMessageType($options);

        if ($this->isSuppressed($recipient, $messageType, $options)) {
            return SmsResult::suppressed($recipient);
        }

        $this->assertOutsideQuietHours($messageType, $options);

        $reference = $this->generateReference();
        $log = $this->openLog($recipient, $message, $reference, 'single', $options);

        try {
            $body = $this->dispatchCampaign(
                recipients: $recipient,
                message: $message,
                options: $options,
                timeout: (int) $this->config['timeout'],
            );
        } catch (DialogEsmsException $e) {
            $this->logFailure($recipient, $e);
            $this->closeLogAsFailure($log, $e);
            $this->events?->dispatch(new SmsFailed($recipient, $message, $e, $log?->id));

            throw $e;
        }

        $this->closeLogAsSent($log, $body);

        $result = SmsResult::success($recipient, $reference, $body, $log?->id);
        $this->events?->dispatch(new SmsSent($result, $message));

        return $result;
    }

    public function sendBulk(iterable $recipients, string $message, array $options = []): BulkResult
    {
        $batchReference = $this->generateReference('batch');
        $partitioned = PhoneNumber::partition($recipients);
        $messageType = $this->resolveMessageType($options);
        $suppressed = [];

        // A campaign has already filtered its list, so re-querying here would
        // repeat the work for every chunk of a large run.
        if ($messageType->respectsOptOut() && ! ($options['suppress_opt_out_filter'] ?? false)) {
            $filtered = OptOut::filter($partitioned['valid'], $options['scope'] ?? null);
            $partitioned['valid'] = $filtered['allowed'];
            $suppressed = $filtered['suppressed'];
        }

        if (! $this->enabled()) {
            return new BulkResult($batchReference, [], [], $partitioned['invalid'], $suppressed);
        }

        $this->assertOutsideQuietHours($messageType, $options);

        if ($partitioned['valid'] === []) {
            throw new DialogEsmsException(
                'Dialog eSMS bulk send has no valid recipients'
                . ($partitioned['invalid'] === [] ? '' : sprintf(' (%d rejected locally)', count($partitioned['invalid'])))
            );
        }

        $chunks = [];
        $chunkSize = max(1, (int) $this->config['chunk_size']);

        foreach (array_chunk($partitioned['valid'], $chunkSize) as $chunk) {
            $chunks[] = $this->sendChunk($chunk, $message, $options, $batchReference);
        }

        return new BulkResult($batchReference, $chunks, $partitioned['valid'], $partitioned['invalid'], $suppressed);
    }

    /**
     * Cost a message before sending it.
     *
     * The API never returns cost and never mentions segmentation, so this is
     * the only warning you get that a Sinhala or Tamil body has tripled the
     * bill.
     */
    public function estimate(string $message, int $recipients = 1): MessageEstimate
    {
        return MessageEstimate::for($message, $recipients);
    }

    /**
     * Start building a queued campaign.
     */
    public function campaign(string $name): CampaignBuilder
    {
        return new CampaignBuilder($name);
    }

    /**
     * Send one chunk as its own campaign.
     *
     * A failed chunk is recorded and reported, never thrown: aborting the loop
     * would leave the remaining recipients unsent with no record of why, and
     * the caller cannot tell which chunks already went out.
     *
     * @param  array<int, string>    $chunk
     * @param  array<string, mixed>  $options
     */
    protected function sendChunk(array $chunk, string $message, array $options, string $batchReference): SmsResult
    {
        $joined = implode(',', $chunk);
        $log = $this->openLog($joined, $message, $batchReference, 'bulk', $options);

        try {
            $body = $this->dispatchCampaign(
                recipients: $joined,
                message: $message,
                options: $options,
                timeout: (int) $this->config['bulk_timeout'],
            );
        } catch (DialogEsmsException $e) {
            $this->logFailure($joined, $e);
            $this->closeLogAsFailure($log, $e);
            $this->events?->dispatch(new SmsFailed($joined, $message, $e, $log?->id));

            return SmsResult::failure(
                recipient: $joined,
                reference: $batchReference,
                error: $e->getMessage(),
                code: $e->responseCode,
                raw: $e->rawResponse,
                logId: $log?->id,
            );
        }

        $this->closeLogAsSent($log, $body);

        return SmsResult::success($joined, $batchReference, $body, $log?->id);
    }

    /**
     * Perform the HTTP call and turn the body into a success or an exception.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws DialogEsmsException
     */
    protected function dispatchCampaign(string $recipients, string $message, array $options, int $timeout): string
    {
        if (trim($message) === '') {
            // Dialog answers 2004 for this, but catching it here saves a round
            // trip and gives a clearer message than "empty message body".
            throw new DialogEsmsException('Dialog eSMS message body is empty');
        }

        $query = $this->buildQuery($recipients, $message, $options);
        $attempts = max(1, (int) $this->config['retries'] + 1);
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http
                    ->timeout($timeout)
                    ->get($this->endpoint('create/url-campaign'), $query);

                $body = $this->assertAccepted($response);

                return $body;
            } catch (DialogEsmsException $e) {
                $lastException = $e;

                if (! $e->isRetryable() || $attempt === $attempts) {
                    throw $e;
                }
            } catch (ConnectionException $e) {
                $lastException = DialogEsmsException::transport($e->getMessage(), $e);

                if ($attempt === $attempts) {
                    throw $lastException;
                }
            } catch (Throwable $e) {
                throw DialogEsmsException::transport($e->getMessage(), $e);
            }

            $this->pauseBeforeRetry($attempt);
        }

        // Unreachable in practice: the loop either returns or throws.
        throw $lastException ?? DialogEsmsException::transport('Dialog eSMS send failed with no response');
    }

    /**
     * Read the outcome out of the response body.
     *
     * The HTTP status is deliberately ignored for anything below 400 — Dialog
     * returns 200 for rejections too, so the body is the only signal.
     *
     * @throws DialogEsmsException
     */
    protected function assertAccepted(Response $response): string
    {
        if ($response->serverError() || $response->clientError()) {
            throw DialogEsmsException::transport(
                sprintf('Dialog eSMS returned HTTP %d', $response->status())
            );
        }

        $body = trim($response->body());

        if (ResponseCode::fromResponse($body) === ResponseCode::Success) {
            return $body;
        }

        // Deliberately no logging here. This method runs inside a try/catch
        // that converts any unexpected Throwable into a transport error, so a
        // logger that blows up would replace a precisely diagnosed rejection
        // ("invalid key") with a meaningless one ("transport error"). Callers
        // log the exception once they have caught it.
        throw DialogEsmsException::fromResponse($body, 'Dialog eSMS rejected the campaign');
    }

    /**
     * Assemble the query string.
     *
     * The parameter names here are load-bearing; see the class docblock.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function buildQuery(string $recipients, string $message, array $options): array
    {
        $query = [
            'esmsqk' => (string) $this->config['api_key'],
            'list' => $recipients,
            'message' => $message,
            'source_address' => (string) ($options['sender_id'] ?? $this->senderOverride ?? $this->config['sender_id']),
        ];

        // Omitted entirely when unset. Handing Dialog an empty
        // push_notification_url is not the same as omitting it, and an empty
        // value has been seen to suppress receipts altogether.
        $pushUrl = $options['push_url'] ?? $this->config['push_url'] ?? null;

        if (is_string($pushUrl) && $pushUrl !== '') {
            $query['push_notification_url'] = $pushUrl;
        }

        return $query;
    }

    public function balance(): Balance
    {
        try {
            $response = $this->http
                ->timeout((int) $this->config['timeout'])
                ->get($this->endpoint('check/balance'), ['esmsqk' => (string) $this->config['api_key']]);

            $body = trim($response->body());

            // Success is `1|1234.5600`. Failure is a bare code with no pipe, so
            // the absence of a delimiter identifies the error rather than being
            // a parse problem — report the code, not "malformed response".
            if (! str_contains($body, '|')) {
                return Balance::unavailable(
                    ResponseCode::describe($body),
                    ResponseCode::fromResponse($body),
                );
            }

            [$status, $amount] = explode('|', $body, 2);

            if (ResponseCode::fromResponse($status) !== ResponseCode::Success) {
                return Balance::unavailable(
                    ResponseCode::describe($status),
                    ResponseCode::fromResponse($status),
                );
            }

            return Balance::of((float) trim($amount));
        } catch (Throwable $e) {
            $this->logger()->error('Dialog eSMS balance check failed', ['error' => $e->getMessage()]);

            return Balance::unavailable($e->getMessage());
        }
    }

    public function validate(string $phone): bool
    {
        return PhoneNumber::isValid($phone);
    }

    /**
     * Delivery status is only ever known from a stored receipt.
     *
     * Dialog exposes no status-lookup endpoint on this API, so this reads the
     * package's own log. It returns null when logging is disabled or the
     * reference is unknown — never a guess.
     */
    public function statusOf(string $reference): ?DeliveryStatus
    {
        if (! $this->loggingEnabled()) {
            return null;
        }

        $log = SmsLog::query()->where('reference', $reference)->latest('id')->first();

        return $log?->status;
    }

    /**
     * What kind of message this is, defaulting to transactional.
     *
     * Defaulting the *safe* way round would mean defaulting to promotional,
     * but that would silently subject every existing OTP call to opt-out
     * filtering and quiet hours the moment this feature landed. Marketing has
     * to be declared explicitly instead — see `MessageType`.
     *
     * @param  array<string, mixed>  $options
     */
    protected function resolveMessageType(array $options): MessageType
    {
        $type = $options['message_type'] ?? MessageType::Transactional;

        return $type instanceof MessageType ? $type : MessageType::from((string) $type);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function isSuppressed(string $recipient, MessageType $type, array $options): bool
    {
        if (! $type->respectsOptOut() || ($options['suppress_opt_out_filter'] ?? false)) {
            return false;
        }

        if (! $this->loggingEnabled()) {
            // The opt-out list lives in the same tables as the logs. Without
            // them there is nothing to consult, and pretending otherwise would
            // silently drop every promotional message.
            return false;
        }

        return OptOut::has($recipient, $options['scope'] ?? null);
    }

    /**
     * Refuse an immediate promotional send inside the quiet window.
     *
     * Throwing rather than silently deferring, because `send()` is the
     * immediate path — a caller who wants deferral should build a campaign,
     * which reschedules to the next permitted moment instead.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws DialogEsmsException
     */
    protected function assertOutsideQuietHours(MessageType $type, array $options): void
    {
        if (! $type->respectsQuietHours() || ($options['ignore_quiet_hours'] ?? false)) {
            return;
        }

        $quietHours = QuietHours::fromConfig();

        if (! $quietHours->isQuietAt()) {
            return;
        }

        throw new DialogEsmsException(sprintf(
            'Refusing to send a promotional message during quiet hours (%s). '
            . 'Queue it as a campaign to send at %s, or pass ignore_quiet_hours.',
            $quietHours->describe(),
            $quietHours->nextPermittedAfter()->format('Y-m-d H:i T'),
        ));
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) $this->config['base_url'], '/') . '/' . ltrim($path, '/');
    }

    protected function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    protected function loggingEnabled(): bool
    {
        return (bool) data_get($this->config, 'logging.enabled', true);
    }

    protected function pauseBeforeRetry(int $attempt): void
    {
        $delayMs = (int) $this->config['retry_delay'] * $attempt;

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    protected function generateReference(string $prefix = 'dialog'): string
    {
        return sprintf('%s_%d_%s', $prefix, time(), bin2hex(random_bytes(4)));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function openLog(string $to, string $message, string $reference, string $type, array $options): ?SmsLog
    {
        if (! $this->loggingEnabled()) {
            return null;
        }

        $notifiable = $options['notifiable'] ?? null;

        $encoding = MessageEncoder::detect($message);

        return SmsLog::create([
            'reference' => $reference,
            'to' => $to,
            'message' => data_get($this->config, 'logging.store_message_body', true) ? $message : null,
            'type' => $type,
            'message_type' => $this->resolveMessageType($options),
            'campaign_id' => $options['campaign_id'] ?? null,
            // Captured now so cost stays reconcilable even when the body is
            // not retained.
            'encoding' => $encoding,
            'segments' => MessageEncoder::segments($message, $encoding),
            'status' => DeliveryStatus::Pending,
            'sender_id' => $options['sender_id'] ?? $this->senderOverride ?? $this->config['sender_id'],
            'notifiable_type' => $notifiable instanceof Model ? $notifiable->getMorphClass() : null,
            'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
            'metadata' => $options['metadata'] ?? null,
        ]);
    }

    protected function closeLogAsSent(?SmsLog $log, string $body): void
    {
        $log?->update([
            'status' => DeliveryStatus::Sent,
            'response_code' => $body,
            'sent_at' => now(),
        ]);
    }

    protected function closeLogAsFailure(?SmsLog $log, DialogEsmsException $e): void
    {
        $log?->update([
            'status' => DeliveryStatus::Failed,
            'response_code' => $e->rawResponse,
            'error' => $e->getMessage(),
            'failed_at' => now(),
        ]);
    }

    /**
     * Record a failure, without ever becoming a failure itself.
     *
     * Diagnostics must not be able to break sending: a misconfigured log
     * channel should cost a log line, not an SMS.
     */
    protected function logFailure(string $recipient, DialogEsmsException $e): void
    {
        try {
            $channel = data_get($this->config, 'logging.channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

            $logger->error('Dialog eSMS send failed', [
                'recipient' => $recipient,
                'code' => $e->rawResponse,
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // Nothing useful to do here.
        }
    }
}
