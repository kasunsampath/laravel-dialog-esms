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
     *
     * The distinction that matters is whether Dialog answered at all, not
     * whether this package recognises what it said.
     *
     * A transport failure — timeout, DNS, connection reset — carries no
     * response body and says nothing about the request, so another attempt is
     * worth making. A rejection with a code this package does not know still
     * means the request arrived and was refused; repeating it verbatim will be
     * refused again, and retrying only delays the error reaching the caller.
     *
     * This distinction was not made originally, so an unrecognised code such
     * as `2012` — observed in production, documented nowhere — was retried as
     * though nothing had been received.
     */
    public function isRetryable(): bool
    {
        if ($this->responseCode !== null) {
            return $this->responseCode->isRetryable();
        }

        // rawResponse is null only for transport failures; a rejection always
        // carries the body it was built from, even an empty one.
        return $this->rawResponse === null;
    }

    public function isBillingIssue(): bool
    {
        return $this->responseCode?->isBillingIssue() ?? false;
    }
}
