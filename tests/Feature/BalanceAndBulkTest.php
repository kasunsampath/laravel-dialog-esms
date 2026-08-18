<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Feature;

use CodeRayTech\DialogEsms\Enums\ResponseCode;
use CodeRayTech\DialogEsms\Exceptions\DialogEsmsException;
use CodeRayTech\DialogEsms\Facades\DialogEsms;
use CodeRayTech\DialogEsms\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class BalanceAndBulkTest extends TestCase
{
    public function test_it_parses_the_pipe_delimited_balance_body(): void
    {
        Http::fake(['*' => Http::response('1|1234.5600', 200)]);

        $balance = DialogEsms::balance();

        $this->assertTrue($balance->available);
        $this->assertSame(1234.56, $balance->amount);
        $this->assertSame('LKR 1,234.56', $balance->formatted());
    }

    public function test_a_balance_error_reports_the_code_not_a_parse_failure(): void
    {
        // Failure comes back as a bare code with no pipe. Reporting "malformed
        // response" here would hide the real cause.
        Http::fake(['*' => Http::response('2007', 200)]);

        $balance = DialogEsms::balance();

        $this->assertFalse($balance->available);
        $this->assertSame(ResponseCode::InvalidKey, $balance->code);
        $this->assertStringContainsString('Invalid key', (string) $balance->error);
    }

    public function test_balance_threshold_check(): void
    {
        Http::fake(['*' => Http::response('1|50.0000', 200)]);

        $this->assertTrue(DialogEsms::balance()->isBelow(100));
    }

    public function test_bulk_sends_recipients_as_a_comma_separated_list(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        DialogEsms::sendBulk(['0772345678', '0771234567'], 'Hello');

        Http::assertSent(fn (Request $r): bool => $r->data()['list'] === '94772345678,94771234567');
    }

    public function test_bulk_filters_invalid_numbers_locally_and_reports_them(): void
    {
        // Dialog silently drops malformed entries, so filtering first is the
        // only way to know who was left out.
        Http::fake(['*' => Http::response('1', 200)]);

        $result = DialogEsms::sendBulk(['0772345678', '0112345678', 'nonsense'], 'Hello');

        $this->assertSame(1, $result->acceptedCount());
        $this->assertSame(['0112345678', 'nonsense'], $result->invalid);
        Http::assertSent(fn (Request $r): bool => $r->data()['list'] === '94772345678');
    }

    public function test_bulk_deduplicates_the_same_number_in_different_forms(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $result = DialogEsms::sendBulk(['0772345678', '+94772345678', '94772345678'], 'Hello');

        $this->assertSame(1, $result->acceptedCount());
    }

    public function test_bulk_splits_into_chunks_beyond_the_configured_size(): void
    {
        // Recipients ride in the query string, so a long list can exceed the
        // server's URL limit.
        config()->set('dialog-esms.chunk_size', 2);
        Http::fake(['*' => Http::response('1', 200)]);

        $result = DialogEsms::sendBulk(
            ['0772345678', '0771234567', '0761111111', '0759999999', '0701234567'],
            'Hello',
        );

        Http::assertSentCount(3);
        $this->assertTrue($result->successful());
        $this->assertSame(5, $result->acceptedCount());
    }

    public function test_a_failing_chunk_does_not_abort_the_remaining_chunks(): void
    {
        config()->set('dialog-esms.chunk_size', 1);

        Http::fakeSequence()
            ->push('1', 200)
            ->push('2011', 200)
            ->push('1', 200);

        $result = DialogEsms::sendBulk(['0772345678', '0771234567', '0761111111'], 'Hello');

        Http::assertSentCount(3);
        $this->assertFalse($result->successful());
        $this->assertTrue($result->partiallyFailed());
        $this->assertCount(1, $result->failedChunks());
    }

    public function test_bulk_with_no_valid_recipients_throws(): void
    {
        Http::fake();

        $this->expectException(DialogEsmsException::class);

        try {
            DialogEsms::sendBulk(['0112345678', 'nonsense'], 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }
}
