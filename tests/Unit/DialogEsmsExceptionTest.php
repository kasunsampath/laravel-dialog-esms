<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Tests\Unit;

use KasunSampath\DialogEsms\Enums\ResponseCode;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DialogEsmsExceptionTest extends TestCase
{
    public function test_a_transport_failure_is_retryable(): void
    {
        // No response at all says nothing about the request, so another
        // attempt is worth making.
        $e = DialogEsmsException::transport('connection reset', new RuntimeException());

        $this->assertTrue($e->isRetryable());
        $this->assertNull($e->rawResponse);
    }

    public function test_a_known_transient_rejection_is_retryable(): void
    {
        $e = DialogEsmsException::fromResponse('2011');

        $this->assertSame(ResponseCode::TransactionalError, $e->responseCode);
        $this->assertTrue($e->isRetryable());
    }

    public function test_a_known_permanent_rejection_is_not_retryable(): void
    {
        $e = DialogEsmsException::fromResponse('2007');

        $this->assertFalse($e->isRetryable());
    }

    public function test_an_unrecognised_rejection_is_not_retryable(): void
    {
        // Dialog answered with a code this package does not know. It is still a
        // rejection: the request was received and refused, so repeating it
        // verbatim will be refused again. Only the absence of any response
        // justifies a retry.
        //
        // This guard was written when 2012 was the unknown code. It has since
        // been identified, so the test uses a code that is still unmapped —
        // the point is the handling of *any* unknown code, not that one.
        $e = DialogEsmsException::fromResponse('2099');

        $this->assertNull($e->responseCode, 'unknown codes must not be mapped to a case');
        $this->assertSame('2099', $e->rawResponse);
        $this->assertFalse($e->isRetryable());
    }

    public function test_an_unrecognised_rejection_still_reports_its_code(): void
    {
        $e = DialogEsmsException::fromResponse('2099');

        $this->assertStringContainsString('2099', $e->getMessage());
        $this->assertStringContainsString('Unknown Dialog response', $e->getMessage());
    }

    public function test_a_known_code_carries_through_to_the_exception_code(): void
    {
        // getCode() is what callers switch on, so a recognised code must not
        // be flattened to 0.
        $this->assertSame(2012, DialogEsmsException::fromResponse('2012')->getCode());
        $this->assertSame(2008, DialogEsmsException::fromResponse('2008')->getCode());
    }

    public function test_an_empty_body_is_treated_as_a_rejection_not_a_transport_error(): void
    {
        // An empty 200 body is still a response. Retrying it would loop.
        $e = DialogEsmsException::fromResponse('');

        $this->assertFalse($e->isRetryable());
    }

    public function test_billing_issues_are_identified_only_for_known_codes(): void
    {
        $this->assertTrue(DialogEsmsException::fromResponse('2008')->isBillingIssue());
        $this->assertFalse(DialogEsmsException::fromResponse('2099')->isBillingIssue());
        $this->assertFalse(DialogEsmsException::transport('boom')->isBillingIssue());
    }
}
