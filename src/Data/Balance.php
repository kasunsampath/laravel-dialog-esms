<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Data;

use CodeRayTech\DialogEsms\Enums\ResponseCode;
use JsonSerializable;

/**
 * Wallet balance.
 *
 * The balance endpoint answers with a pipe-delimited body — `1|1234.5600` —
 * not JSON. On failure it answers with a bare status code and no pipe at all,
 * so the missing delimiter is itself the error signal.
 */
final readonly class Balance implements JsonSerializable
{
    public function __construct(
        public bool $available,
        public ?float $amount = null,
        public string $currency = 'LKR',
        public ?string $error = null,
        public ?ResponseCode $code = null,
    ) {}

    public static function of(float $amount): self
    {
        return new self(available: true, amount: $amount);
    }

    public static function unavailable(string $error, ?ResponseCode $code = null): self
    {
        return new self(available: false, error: $error, code: $code);
    }

    public function formatted(): string
    {
        return $this->available
            ? sprintf('%s %s', $this->currency, number_format((float) $this->amount, 2))
            : 'unavailable';
    }

    public function isBelow(float $threshold): bool
    {
        return $this->available && (float) $this->amount < $threshold;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->formatted(),
            'error' => $this->error,
            'code' => $this->code?->value,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
