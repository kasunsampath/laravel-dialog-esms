<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Exceptions;

use KasunSampath\DialogEsms\Enums\ResponseCode;
use RuntimeException;
use Throwable;

class DialogEsmsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ResponseCode $responseCode = null,
        public readonly ?string $rawResponse = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, (int) ($responseCode?->value ?? 0), $previous);
    }

    /**
     * Build from a raw Dialog response body.
     */
    public static function fromResponse(string $body, string $context = 'Dialog eSMS request failed'): self
    {
        $code = ResponseCode::fromResponse($body);

        return new self(
            sprintf('%s: %s', $context, ResponseCode::describe($body)),
            $code,
            trim($body),
        );
    }

    public static function transport(string $message, ?Throwable $previous = null): self
    {
        return new self('Dialog eSMS transport error: ' . $message, null, null, $previous);
    }

    public static function invalidRecipient(string $phone): self
    {
        return new self(sprintf('Invalid Sri Lankan mobile number: "%s"', $phone));
    }

    /**
     * Whether resending the same payload could plausibly succeed.
     */
    public function isRetryable(): bool
    {
        // A transport failure (timeout, DNS, connection reset) says nothing
        // about the request itself, so it is always worth another attempt.
        return $this->responseCode?->isRetryable() ?? true;
    }

    public function isBillingIssue(): bool
    {
        return $this->responseCode?->isBillingIssue() ?? false;
    }
}
