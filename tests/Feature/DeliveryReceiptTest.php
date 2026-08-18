<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Feature;

use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Events\SmsDelivered;
use CodeRayTech\DialogEsms\Facades\DialogEsms;
use CodeRayTech\DialogEsms\Models\SmsLog;
use CodeRayTech\DialogEsms\Models\SmsWebhook;
use CodeRayTech\DialogEsms\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class DeliveryReceiptTest extends TestCase
{
    protected function sendOne(string $to = '0772345678'): SmsLog
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $result = DialogEsms::send($to, 'Hello');

        return SmsLog::findOrFail($result->logId);
    }

    public function test_it_accepts_a_receipt_delivered_as_a_get(): void
    {
        // This is how Dialog actually calls it. A POST-only route answers 405
        // and every receipt is lost with nothing in the logs to show for it.
        $log = $this->sendOne();

        $this->get(route('dialog-esms.webhook', [
            'campaignId' => '123456789',
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $log->refresh()->status);
        $this->assertNotNull($log->delivered_at);
    }

    public function test_it_also_accepts_a_post(): void
    {
        $log = $this->sendOne();

        $this->post(route('dialog-esms.webhook'), [
            'campaignId' => '123456789',
            'msisdn' => '94772345678',
            'status' => '1',
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $log->refresh()->status);
    }

    public function test_it_adopts_the_dialog_campaign_id_from_the_receipt(): void
    {
        // The send response carries no id at all, so the receipt is the only
        // chance to learn the id that is searchable in Dialog's portal.
        $log = $this->sendOne();
        $this->assertNull($log->dialog_campaign_id);

        $this->get(route('dialog-esms.webhook', [
            'campaignId' => '123456789',
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertOk();

        $this->assertSame('123456789', $log->refresh()->dialog_campaign_id);
    }

    public function test_it_correlates_by_number_across_different_spellings(): void
    {
        $log = $this->sendOne('0772345678');

        // Receipt echoes the number in a different form than we submitted.
        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '+94 77 234 5678',
            'status' => '1',
        ]))->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $log->refresh()->status);
    }

    public function test_it_stores_a_receipt_it_cannot_correlate_and_still_answers_200(): void
    {
        // Rejecting an unmatched receipt with a 4xx would make Dialog retry or
        // drop it, and we would stop learning about format changes.
        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94770000000',
            'status' => '1',
        ]))->assertOk();

        $webhook = SmsWebhook::latest('id')->first();

        $this->assertNotNull($webhook);
        $this->assertNull($webhook->sms_log_id);
    }

    public function test_it_stores_a_completely_unrecognised_payload(): void
    {
        $this->get(route('dialog-esms.webhook', ['something' => 'unexpected']))->assertOk();

        $webhook = SmsWebhook::latest('id')->first();

        $this->assertNotNull($webhook);
        $this->assertSame(['something' => 'unexpected'], $webhook->payload);
    }

    public function test_an_unknown_status_does_not_fabricate_a_delivery_or_a_failure(): void
    {
        $log = $this->sendOne();

        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94772345678',
            'status' => 'WOBBLE',
        ]))->assertOk();

        $log->refresh();

        $this->assertSame(DeliveryStatus::Sent, $log->status);
        $this->assertNull($log->delivered_at);
        $this->assertNull($log->failed_at);
    }

    public function test_it_records_the_raw_status_before_mapping(): void
    {
        $this->sendOne();

        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertOk();

        $webhook = SmsWebhook::latest('id')->first();

        $this->assertSame('1', $webhook->raw_status);
        $this->assertSame('delivered', $webhook->mapped_status);
        $this->assertSame('GET', $webhook->http_method);
    }

    public function test_it_dispatches_a_delivered_event(): void
    {
        Event::fake([SmsDelivered::class]);
        $this->sendOne();

        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertOk();

        Event::assertDispatched(SmsDelivered::class);
    }

    public function test_signature_checking_is_off_unless_a_secret_is_configured(): void
    {
        // Dialog publishes no signing scheme; a placeholder secret would reject
        // every genuine receipt.
        $this->sendOne();

        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertOk();
    }

    public function test_a_configured_secret_rejects_an_unsigned_receipt(): void
    {
        config()->set('dialog-esms.webhook_secret', 'shh');

        $this->get(route('dialog-esms.webhook', [
            'msisdn' => '94772345678',
            'status' => '1',
        ]))->assertUnauthorized();
    }
}
