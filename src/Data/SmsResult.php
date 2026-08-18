<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Data;

use KasunSampath\DialogEsms\Enums\ResponseCode;
use JsonSerializable;

/**
 * Outcome of a single send.
 *
 * `reference` is generated locally, not by Dialog. The send call returns only
 * a status code — there is no message id anywhere in the response — so there
 * is nothing from Dialog to store at send time. The delivery receipt does
 * carry their real `campaignId`, and the package adopts it when it arrives.
 */
final readonly class SmsResult implements JsonSerializable
{
    public function __construct(
        public bool $successful,
        public string $recipient,
        public string $reference,
        public ?ResponseCode $code = null,
        public ?string $rawResponse = null,
        public ?string $error = null,
        public ?int $logId = null,
        public bool $skipped = false,
        public bool $suppressed = false,
    ) {}

    public static function success(string $recipient, string $reference, string $raw, ?int $logId = null): self
    {
        return new self(
            successful: true,
            recipient: $recipient,
            reference: $reference,
            code: ResponseCode::Success,
            rawResponse: $raw,
            logId: $logId,
        );
    }

    public static function failure(
        string $recipient,
        string $reference,
        string $error,
        ?ResponseCode $code = null,
        ?string $raw = null,
        ?int $logId = null,
    ): self {
        return new self(
            successful: false,
            recipient: $recipient,
            reference: $reference,
            code: $code,
            rawResponse: $raw,
            error: $error,
            logId: $logId,
        );
    }

    /**
     * Sending was disabled by configuration: nothing was sent, nothing failed.
     */
    public static function skipped(string $recipient, string $reason): self
    {
        return new self(
            successful: false,
            recipient: $recipient,
            reference: '',
            error: $reason,
            skipped: true,
        );
    }

    /**
     * The recipient has opted out, so nothing was sent.
     *
     * Modelled as skipped rather than failed. A suppression is the system
     * working correctly, and treating it as an error means opt-outs light up
     * failure dashboards and get "fixed".
     */
    public static function suppressed(string $recipient): self
    {
        return new self(
            successful: false,
            recipient: $recipient,
            reference: '',
            error: 'Recipient has opted out of promotional messages',
            skipped: true,
            suppressed: true,
        );
    }

    public function failed(): bool
    {
        return ! $this->successful && ! $this->skipped;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'skipped' => $this->skipped,
            'suppressed' => $this->suppressed,
            'recipient' => $this->recipient,
            'reference' => $this->reference,
            'code' => $this->code?->value,
            'error' => $this->error,
            'log_id' => $this->logId,
            'raw_response' => $this->rawResponse,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
