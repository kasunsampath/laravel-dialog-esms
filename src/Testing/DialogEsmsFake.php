<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Testing;

use KasunSampath\DialogEsms\Campaigns\CampaignBuilder;
use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Data\Balance;
use KasunSampath\DialogEsms\Data\BulkResult;
use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Data\SmsResult;
use KasunSampath\DialogEsms\Enums\ResponseCode;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Support\PhoneNumber;
use PHPUnit\Framework\Assert;

/**
 * Test double that records sends instead of spending credit.
 *
 * Recipient validation still runs, so a test using a badly formatted number
 * fails the same way production would rather than passing against the fake.
 */
class DialogEsmsFake implements SmsGateway
{
    /** @var array<int, array{to: string, message: string, options: array<string, mixed>}> */
    protected array $sent = [];

    protected ?ResponseCode $failWith = null;

    protected float $balance = 10_000.0;

    /**
     * Make every subsequent send fail with the given code.
     */
    public function shouldFailWith(ResponseCode $code): static
    {
        $this->failWith = $code;

        return $this;
    }

    public function shouldHaveBalance(float $amount): static
    {
        $this->balance = $amount;

        return $this;
    }

    public function send(string $to, string $message, array $options = []): SmsResult
    {
        if (! PhoneNumber::isValid($to)) {
            throw DialogEsmsException::invalidRecipient($to);
        }

        $recipient = PhoneNumber::normalize($to);
        $this->sent[] = ['to' => $recipient, 'message' => $message, 'options' => $options];

        if ($this->failWith !== null) {
            throw DialogEsmsException::fromResponse($this->failWith->value, 'Dialog eSMS rejected the campaign');
        }

        return SmsResult::success($recipient, 'fake_' . count($this->sent), '1');
    }

    public function sendBulk(iterable $recipients, string $message, array $options = []): BulkResult
    {
        $partitioned = PhoneNumber::partition($recipients);
        $chunks = [];

        foreach ($partitioned['valid'] as $recipient) {
            $this->sent[] = ['to' => $recipient, 'message' => $message, 'options' => $options];
        }

        if ($partitioned['valid'] !== []) {
            $chunks[] = $this->failWith !== null
                ? SmsResult::failure(implode(',', $partitioned['valid']), 'fake_batch', $this->failWith->message(), $this->failWith)
                : SmsResult::success(implode(',', $partitioned['valid']), 'fake_batch', '1');
        }

        return new BulkResult('fake_batch', $chunks, $partitioned['valid'], $partitioned['invalid']);
    }

    public function balance(): Balance
    {
        return Balance::of($this->balance);
    }

    public function validate(string $phone): bool
    {
        return PhoneNumber::isValid($phone);
    }

    /**
     * Real segmentation maths, not a stub — a test asserting a message fits
     * one segment must fail when it stops fitting.
     */
    public function estimate(string $message, int $recipients = 1): MessageEstimate
    {
        return MessageEstimate::for($message, $recipients);
    }

    public function usingSender(string $senderId): static
    {
        return $this;
    }

    /**
     * Campaigns run through the real builder even under the fake.
     *
     * The builder's job is recipient filtering and costing, which is exactly
     * what a campaign test wants to exercise; only the eventual send is faked.
     */
    public function campaign(string $name): CampaignBuilder
    {
        return new CampaignBuilder($name);
    }

    // ---------------------------------------------------------------- asserts

    /**
     * @param  (callable(string, string, array<string, mixed>): bool)|null  $callback
     */
    public function assertSent(?callable $callback = null): void
    {
        Assert::assertNotEmpty($this->sent, 'No Dialog eSMS messages were sent.');

        if ($callback !== null) {
            Assert::assertTrue(
                $this->matching($callback) !== [],
                'No sent Dialog eSMS message matched the given condition.',
            );
        }
    }

    public function assertSentTo(string $phone, ?string $contains = null): void
    {
        $normalized = PhoneNumber::normalize($phone);

        $matches = array_filter(
            $this->sent,
            static fn (array $m): bool => $m['to'] === $normalized
                || str_contains($m['to'], $normalized),
        );

        Assert::assertNotEmpty($matches, sprintf('No Dialog eSMS message was sent to %s.', $normalized));

        if ($contains !== null) {
            Assert::assertTrue(
                array_filter($matches, static fn (array $m): bool => str_contains($m['message'], $contains)) !== [],
                sprintf('No message to %s contained "%s".', $normalized, $contains),
            );
        }
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty($this->sent, sprintf('Expected no Dialog eSMS messages, %d were sent.', count($this->sent)));
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent);
    }

    /**
     * @param  callable(string, string, array<string, mixed>): bool  $callback
     * @return array<int, array{to: string, message: string, options: array<string, mixed>}>
     */
    protected function matching(callable $callback): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (array $m): bool => $callback($m['to'], $m['message'], $m['options']),
        ));
    }

    /** @return array<int, array{to: string, message: string, options: array<string, mixed>}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
