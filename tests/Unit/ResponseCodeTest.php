<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Unit;

use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Enums\ResponseCode;
use PHPUnit\Framework\TestCase;

class ResponseCodeTest extends TestCase
{
    public function test_success_is_the_single_character_one(): void
    {
        $this->assertSame(ResponseCode::Success, ResponseCode::fromResponse('1'));
    }

    public function test_it_tolerates_surrounding_whitespace(): void
    {
        // The body arrives as plain text and has been seen with a trailing
        // newline, which would defeat a strict === '1' comparison.
        $this->assertSame(ResponseCode::Success, ResponseCode::fromResponse("1\n"));
        $this->assertSame(ResponseCode::InvalidKey, ResponseCode::fromResponse(' 2007 '));
    }

    public function test_2007_is_an_invalid_key_not_a_balance_problem(): void
    {
        // Guarding the single most expensive misreading of this API.
        $this->assertSame(ResponseCode::InvalidKey, ResponseCode::from('2007'));
        $this->assertStringContainsString('Invalid key', ResponseCode::InvalidKey->message());
        $this->assertFalse(ResponseCode::InvalidKey->isBillingIssue());
    }

    public function test_2008_is_the_actual_out_of_credit_code(): void
    {
        $this->assertSame(ResponseCode::InsufficientBalance, ResponseCode::from('2008'));
        $this->assertTrue(ResponseCode::InsufficientBalance->isBillingIssue());
    }

    public function test_unknown_codes_do_not_resolve_to_a_case(): void
    {
        $this->assertNull(ResponseCode::fromResponse('9999'));
        $this->assertNull(ResponseCode::fromResponse(''));
    }

    public function test_unknown_codes_are_described_without_guessing(): void
    {
        $this->assertStringContainsString('Unknown Dialog response', ResponseCode::describe('9999'));
        $this->assertStringContainsString('9999', ResponseCode::describe('9999'));
        $this->assertStringContainsString('<empty>', ResponseCode::describe('   '));
    }

    public function test_only_transient_codes_are_retryable(): void
    {
        $this->assertTrue(ResponseCode::TransactionalError->isRetryable());
        $this->assertTrue(ResponseCode::CampaignCreationError->isRetryable());

        // Retrying these burns time and changes nothing — the request itself
        // is the problem.
        $this->assertFalse(ResponseCode::InvalidKey->isRetryable());
        $this->assertFalse(ResponseCode::EmptyMessageBody->isRetryable());
        $this->assertFalse(ResponseCode::InsufficientBalance->isRetryable());
    }

    public function test_every_case_has_a_message(): void
    {
        foreach (ResponseCode::cases() as $case) {
            $this->assertNotSame('', $case->message(), $case->name . ' has no message');
        }
    }

    public function test_receipt_status_one_means_delivered(): void
    {
        // The only value observed in production.
        $this->assertSame(DeliveryStatus::Delivered, DeliveryStatus::fromReceipt('1'));
    }

    public function test_unknown_receipt_status_stays_at_sent(): void
    {
        // Never invent a delivery or a failure from an unrecognised token.
        $this->assertSame(DeliveryStatus::Sent, DeliveryStatus::fromReceipt('WOBBLE'));
        $this->assertSame(DeliveryStatus::Sent, DeliveryStatus::fromReceipt(null));
        $this->assertSame(DeliveryStatus::Sent, DeliveryStatus::fromReceipt(''));
    }

    public function test_receipt_failure_tokens_map_to_failed(): void
    {
        foreach (['failed', 'REJECTD', 'expired', 'undeliv', '0'] as $token) {
            $this->assertSame(DeliveryStatus::Failed, DeliveryStatus::fromReceipt($token), $token);
        }
    }
}
