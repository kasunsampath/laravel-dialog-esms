<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Tests\Feature;

use KasunSampath\DialogEsms\Enums\ResponseCode;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Facades\DialogEsms;
use KasunSampath\DialogEsms\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class FakeTest extends TestCase
{
    public function test_the_fake_records_sends_without_calling_dialog(): void
    {
        Http::fake();
        $fake = DialogEsms::fake();

        DialogEsms::send('0772345678', 'Your code is 4821');

        $fake->assertSentTo('0772345678', 'code is 4821');
        $fake->assertSentCount(1);
        Http::assertNothingSent();
    }

    public function test_the_fake_normalises_the_recipient_for_assertions(): void
    {
        $fake = DialogEsms::fake();

        DialogEsms::send('+94772345678', 'Hello');

        // Asserting in a different spelling than the send still matches.
        $fake->assertSentTo('0772345678');
    }

    public function test_the_fake_still_rejects_invalid_numbers(): void
    {
        // A test that passes against the fake but fails in production is worse
        // than no test, so validation is not skipped.
        DialogEsms::fake();

        $this->expectException(DialogEsmsException::class);

        DialogEsms::send('0112345678', 'Hello');
    }

    public function test_the_fake_can_simulate_a_failure_code(): void
    {
        $fake = DialogEsms::fake()->shouldFailWith(ResponseCode::InsufficientBalance);

        try {
            DialogEsms::send('0772345678', 'Hello');
            $this->fail('Expected a DialogEsmsException.');
        } catch (DialogEsmsException $e) {
            $this->assertTrue($e->isBillingIssue());
        }

        // The attempt is still recorded.
        $fake->assertSentCount(1);
    }

    public function test_assert_nothing_sent(): void
    {
        DialogEsms::fake()->assertNothingSent();
    }
}
