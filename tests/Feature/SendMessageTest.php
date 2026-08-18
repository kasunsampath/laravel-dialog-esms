<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Feature;

use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Enums\ResponseCode;
use CodeRayTech\DialogEsms\Events\SmsFailed;
use CodeRayTech\DialogEsms\Events\SmsSent;
use CodeRayTech\DialogEsms\Exceptions\DialogEsmsException;
use CodeRayTech\DialogEsms\Facades\DialogEsms;
use CodeRayTech\DialogEsms\Models\SmsLog;
use CodeRayTech\DialogEsms\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class SendMessageTest extends TestCase
{
    public function test_it_sends_with_the_exact_parameter_names_dialog_requires(): void
    {
        Http::fake([
            '*' => Http::response('1', 200),
        ]);

        DialogEsms::send('0772345678', 'Your code is 4821');

        Http::assertSent(function (Request $request): bool {
            $query = $request->data();

            // These four names are load-bearing. Any other spelling — apiKey,
            // numbers, sourceAddress — is accepted by the HTTP layer and
            // rejected by Dialog as 2007, which reads as a credentials fault.
            $this->assertSame('test-key', $query['esmsqk']);
            $this->assertSame('94772345678', $query['list']);
            $this->assertSame('Your code is 4821', $query['message']);
            $this->assertSame('TESTAPP', $query['source_address']);

            return str_contains($request->url(), '/create/url-campaign');
        });
    }

    public function test_it_treats_a_200_response_carrying_an_error_code_as_a_failure(): void
    {
        // The whole point: Dialog answers HTTP 200 for rejections too, so
        // anything branching on the status code sees a success here.
        Http::fake([
            '*' => Http::response('2008', 200),
        ]);

        try {
            DialogEsms::send('0772345678', 'Hello');
            $this->fail('Expected a DialogEsmsException.');
        } catch (DialogEsmsException $e) {
            $this->assertSame(ResponseCode::InsufficientBalance, $e->responseCode);
            $this->assertTrue($e->isBillingIssue());
        }
    }

    public function test_it_reports_2007_as_an_invalid_key(): void
    {
        Http::fake(['*' => Http::response('2007', 200)]);

        $this->expectException(DialogEsmsException::class);
        $this->expectExceptionMessageMatches('/Invalid key/');

        DialogEsms::send('0772345678', 'Hello');
    }

    public function test_it_omits_the_push_url_when_none_is_configured(): void
    {
        // An empty push_notification_url is not the same as omitting it, and
        // has been seen to suppress receipts entirely.
        config()->set('dialog-esms.push_url', null);
        Http::fake(['*' => Http::response('1', 200)]);

        DialogEsms::send('0772345678', 'Hello');

        Http::assertSent(fn (Request $r): bool => ! array_key_exists('push_notification_url', $r->data()));
    }

    public function test_it_includes_the_push_url_when_configured(): void
    {
        config()->set('dialog-esms.push_url', 'https://example.test/webhooks/dialog-esms');
        Http::fake(['*' => Http::response('1', 200)]);

        DialogEsms::send('0772345678', 'Hello');

        Http::assertSent(fn (Request $r): bool => $r->data()['push_notification_url'] === 'https://example.test/webhooks/dialog-esms');
    }

    public function test_it_rejects_an_invalid_recipient_before_spending_credit(): void
    {
        Http::fake();

        $this->expectException(DialogEsmsException::class);

        try {
            DialogEsms::send('0112345678', 'Hello');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_logs_a_send_and_marks_it_sent_not_delivered(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $result = DialogEsms::send('0772345678', 'Hello');

        $log = SmsLog::find($result->logId);

        $this->assertNotNull($log);
        $this->assertSame('94772345678', $log->to);
        // Acceptance is not delivery. Only a receipt can move this to delivered.
        $this->assertSame(DeliveryStatus::Sent, $log->status);
        $this->assertNull($log->delivered_at);
        $this->assertNotNull($log->sent_at);
    }

    public function test_it_records_a_failed_send(): void
    {
        Http::fake(['*' => Http::response('2004', 200)]);

        try {
            DialogEsms::send('0772345678', 'Hello');
        } catch (DialogEsmsException) {
            // expected
        }

        $log = SmsLog::latest('id')->first();

        $this->assertSame(DeliveryStatus::Failed, $log->status);
        $this->assertSame('2004', $log->response_code);
        $this->assertNotNull($log->failed_at);
    }

    public function test_it_dispatches_events(): void
    {
        Event::fake([SmsSent::class, SmsFailed::class]);
        Http::fake(['*' => Http::response('1', 200)]);

        DialogEsms::send('0772345678', 'Hello');

        Event::assertDispatched(SmsSent::class);
        Event::assertNotDispatched(SmsFailed::class);
    }

    public function test_it_does_not_retry_a_rejection_that_will_never_succeed(): void
    {
        config()->set('dialog-esms.retries', 3);
        Http::fake(['*' => Http::response('2007', 200)]);

        try {
            DialogEsms::send('0772345678', 'Hello');
        } catch (DialogEsmsException) {
            // expected
        }

        // A bad key fails identically forever; retrying only burns time.
        Http::assertSentCount(1);
    }

    public function test_it_retries_a_transient_transactional_error(): void
    {
        config()->set('dialog-esms.retries', 2);

        Http::fakeSequence()
            ->push('2011', 200)
            ->push('1', 200);

        $result = DialogEsms::send('0772345678', 'Hello');

        $this->assertTrue($result->successful);
        Http::assertSentCount(2);
    }

    public function test_it_skips_sending_when_disabled(): void
    {
        config()->set('dialog-esms.enabled', false);
        Http::fake();

        $result = DialogEsms::send('0772345678', 'Hello');

        $this->assertTrue($result->skipped);
        $this->assertFalse($result->failed());
        Http::assertNothingSent();
    }

    public function test_it_can_override_the_sender_mask(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        DialogEsms::usingSender('OTHERID')->send('0772345678', 'Hello');

        Http::assertSent(fn (Request $r): bool => $r->data()['source_address'] === 'OTHERID');
    }

    public function test_it_rejects_an_empty_message_without_a_round_trip(): void
    {
        Http::fake();

        $this->expectException(DialogEsmsException::class);

        try {
            DialogEsms::send('0772345678', '   ');
        } finally {
            Http::assertNothingSent();
        }
    }
}
