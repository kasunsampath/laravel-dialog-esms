<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Data;

use Countable;
use JsonSerializable;

/**
 * Outcome of a bulk campaign, possibly spanning several chunks.
 *
 * Dialog reports one status code per campaign, never per recipient, so an
 * accepted chunk means "queued for every number in this chunk" and nothing
 * more. Per-recipient outcomes arrive later as delivery receipts, if at all.
 *
 * `invalid` holds numbers this package rejected before sending. Dialog drops
 * malformed entries without saying which, so filtering locally is the only way
 * to know who was left out.
 */
final readonly class BulkResult implements Countable, JsonSerializable
{
    /**
     * @param  array<int, SmsResult>  $chunks    One result per campaign sent.
     * @param  array<int, string>     $accepted  Numbers handed to Dialog.
     * @param  array<int, string>     $invalid   Numbers rejected before sending.
     * @param  array<int, string>     $suppressed Numbers removed by the opt-out list.
     */
    public function __construct(
        public string $batchReference,
        public array $chunks,
        public array $accepted,
        public array $invalid = [],
        public array $suppressed = [],
    ) {}

    public function successful(): bool
    {
        return $this->chunks !== [] && $this->failedChunks() === [];
    }

    public function partiallyFailed(): bool
    {
        $failed = $this->failedChunks();

        return $failed !== [] && count($failed) < count($this->chunks);
    }

    /** @return array<int, SmsResult> */
    public function failedChunks(): array
    {
        return array_values(array_filter($this->chunks, static fn (SmsResult $r): bool => $r->failed()));
    }

    public function acceptedCount(): int
    {
        return count($this->accepted);
    }

    public function invalidCount(): int
    {
        return count($this->invalid);
    }

    public function suppressedCount(): int
    {
        return count($this->suppressed);
    }

    public function count(): int
    {
        return count($this->accepted) + count($this->invalid) + count($this->suppressed);
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return array_values(array_filter(array_map(
            static fn (SmsResult $r): ?string => $r->error,
            $this->failedChunks(),
        )));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_reference' => $this->batchReference,
            'successful' => $this->successful(),
            'partially_failed' => $this->partiallyFailed(),
            'accepted' => $this->acceptedCount(),
            'invalid' => $this->invalid,
            'suppressed' => $this->suppressed,
            'chunks' => array_map(static fn (SmsResult $r): array => $r->toArray(), $this->chunks),
            'errors' => $this->errors(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
