<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Tests\Feature;

use Carbon\CarbonImmutable;
use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Enums\CampaignStatus;
use KasunSampath\DialogEsms\Enums\DeliveryStatus;
use KasunSampath\DialogEsms\Enums\Encoding;
use KasunSampath\DialogEsms\Enums\MessageType;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use KasunSampath\DialogEsms\Facades\DialogEsms;
use KasunSampath\DialogEsms\Jobs\SendCampaignChunk;
use KasunSampath\DialogEsms\Models\Campaign;
use KasunSampath\DialogEsms\Models\OptOut;
use KasunSampath\DialogEsms\Models\SmsLog;
use KasunSampath\DialogEsms\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class CampaignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fix the clock outside quiet hours so scheduling assertions are not
        // dependent on when the suite happens to run.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 12:00', 'Asia/Colombo'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_queues_one_job_per_chunk(): void
    {
        Queue::fake();
        config()->set('dialog-esms.chunk_size', 2);

        $campaign = DialogEsms::campaign('Flash sale')
            ->message('Sale ends Friday')
            ->to(['0772345678', '0771234567', '0761111111', '0759999999', '0701234567'])
            ->promotional()
            ->dispatch();

        Queue::assertPushed(SendCampaignChunk::class, 3);
        $this->assertSame(CampaignStatus::Queued, $campaign->status);
    }

    public function test_it_costs_the_campaign_up_front(): void
    {
        Queue::fake();

        $campaign = DialogEsms::campaign('Sinhala promo')
            ->message(str_repeat('ක', 200))
            ->to(['0772345678', '0771234567'])
            ->promotional()
            ->dispatch();

        // 200 Sinhala characters is 3 UCS-2 segments, so 2 recipients cost 6.
        $this->assertSame(Encoding::Ucs2, $campaign->encoding);
        $this->assertSame(3, $campaign->segments_per_message);
        $this->assertSame(6, $campaign->billable_messages);
    }

    public function test_estimate_does_not_create_or_send_anything(): void
    {
        Queue::fake();

        $estimate = DialogEsms::campaign('Dry run')
            ->message('Hello')
            ->to(['0772345678'])
            ->estimate();

        $this->assertSame(1, $estimate->billableMessages);
        $this->assertSame(0, Campaign::count());
        Queue::assertNothingPushed();
    }

    public function test_it_removes_opted_out_recipients_from_a_promotional_campaign(): void
    {
        Queue::fake();
        OptOut::add('0771234567', 'replied STOP');

        $campaign = DialogEsms::campaign('Promo')
            ->message('Sale ends Friday')
            ->to(['0772345678', '0771234567'])
            ->promotional()
            ->dispatch();

        $this->assertSame(1, $campaign->suppressed_count);
        $this->assertSame(['94771234567'], $campaign->suppressed_numbers);
        $this->assertSame(2, $campaign->total_recipients);
        $this->assertSame(0, $campaign->invalid_count);

        // Only the one allowed recipient is costed.
        $this->assertSame(1, $campaign->billable_messages);
    }

    public function test_an_opt_out_recorded_in_one_format_suppresses_all_of_them(): void
    {
        Queue::fake();
        OptOut::add('0771234567');

        $campaign = DialogEsms::campaign('Promo')
            ->message('Hello')
            ->to(['+94771234567'])
            ->promotional()
            ->dispatch();

        $this->assertSame(1, $campaign->suppressed_count);
    }

    public function test_a_transactional_campaign_ignores_the_opt_out_list(): void
    {
        // An OTP must never be blocked by a marketing unsubscribe.
        Queue::fake();
        OptOut::add('0771234567');

        $campaign = DialogEsms::campaign('OTP batch')
            ->message('Your code is 4821')
            ->to(['0771234567'])
            ->transactional()
            ->dispatch();

        $this->assertSame(0, $campaign->suppressed_count);
        Queue::assertPushed(SendCampaignChunk::class, 1);
    }

    public function test_a_scoped_opt_out_only_blocks_its_own_scope(): void
    {
        Queue::fake();
        OptOut::add('0771234567', scope: 'newsletter');

        $blocked = DialogEsms::campaign('Newsletter')
            ->message('Hello')->to(['0771234567'])->promotional('newsletter')->dispatch();

        $allowed = DialogEsms::campaign('Offers')
            ->message('Hello')->to(['0771234567'])->promotional('offers')->dispatch();

        $this->assertSame(1, $blocked->suppressed_count);
        $this->assertSame(0, $allowed->suppressed_count);
    }

    public function test_a_global_opt_out_blocks_every_scope(): void
    {
        Queue::fake();
        OptOut::add('0771234567');

        $campaign = DialogEsms::campaign('Offers')
            ->message('Hello')->to(['0771234567'])->promotional('offers')->dispatch();

        $this->assertSame(1, $campaign->suppressed_count);
    }

    public function test_invalid_numbers_are_reported_separately_from_suppressions(): void
    {
        Queue::fake();
        OptOut::add('0771234567');

        $campaign = DialogEsms::campaign('Promo')
            ->message('Hello')
            ->to(['0772345678', '0771234567', '0112345678', 'nonsense'])
            ->promotional()
            ->dispatch();

        $this->assertSame(1, $campaign->suppressed_count);
        $this->assertSame(2, $campaign->invalid_count);
        $this->assertSame(['0112345678', 'nonsense'], $campaign->invalid_numbers);
    }

    public function test_a_campaign_with_everyone_suppressed_completes_without_queueing(): void
    {
        Queue::fake();
        OptOut::add('0772345678');

        $campaign = DialogEsms::campaign('Promo')
            ->message('Hello')->to(['0772345678'])->promotional()->dispatch();

        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        Queue::assertNothingPushed();
    }

    public function test_a_promotional_campaign_built_at_night_is_deferred(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 23:30', 'Asia/Colombo'));

        $campaign = DialogEsms::campaign('Late promo')
            ->message('Hello')->to(['0772345678'])->promotional()->dispatch();

        // Held to 08:00 the next morning rather than sent at 23:30 or dropped.
        //
        // Reloaded from the database on purpose. Quiet hours are reasoned about
        // in Asia/Colombo but the column is stored in the application timezone,
        // and an in-memory assertion would pass against a Carbon instance that
        // never round-tripped — which is exactly how this was wrong once: the
        // stored value came back 13:30, five and a half hours late.
        $stored = Campaign::findOrFail($campaign->id);

        $this->assertNotNull($stored->scheduled_for);
        $this->assertSame(
            '2026-08-19 08:00',
            $stored->scheduled_for->setTimezone('Asia/Colombo')->format('Y-m-d H:i'),
        );
    }

    public function test_an_explicit_schedule_also_survives_the_round_trip(): void
    {
        Queue::fake();

        $campaign = DialogEsms::campaign('Scheduled')
            ->message('Hello')
            ->to(['0772345678'])
            ->promotional()
            ->scheduleFor(CarbonImmutable::parse('2026-08-20 14:00', 'Asia/Colombo'))
            ->dispatch();

        $stored = Campaign::findOrFail($campaign->id);

        $this->assertSame(
            '2026-08-20 14:00',
            $stored->scheduled_for->setTimezone('Asia/Colombo')->format('Y-m-d H:i'),
        );
    }

    public function test_a_transactional_campaign_at_night_is_not_deferred(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 23:30', 'Asia/Colombo'));

        $campaign = DialogEsms::campaign('OTP')
            ->message('Your code is 4821')->to(['0772345678'])->transactional()->dispatch();

        $this->assertNull($campaign->scheduled_for);
    }

    public function test_quiet_hours_can_be_overridden_explicitly(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-18 23:30', 'Asia/Colombo'));

        $campaign = DialogEsms::campaign('Urgent')
            ->message('Hello')->to(['0772345678'])->promotional()->ignoringQuietHours()->dispatch();

        $this->assertNull($campaign->scheduled_for);
    }

    public function test_an_empty_campaign_is_rejected(): void
    {
        $this->expectException(DialogEsmsException::class);

        DialogEsms::campaign('Empty')->message('Hello')->dispatch();
    }

    public function test_a_campaign_with_no_body_is_rejected(): void
    {
        $this->expectException(DialogEsmsException::class);

        DialogEsms::campaign('Bodyless')->to(['0772345678'])->dispatch();
    }

    // ------------------------------------------------------------- execution

    public function test_running_a_chunk_sends_and_records_against_the_campaign(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $campaign = Campaign::create([
            'name' => 'Test',
            'message' => 'Hello',
            'type' => MessageType::Promotional,
            'status' => CampaignStatus::Queued,
        ]);

        (new SendCampaignChunk($campaign->id, ['94772345678'], true))
            ->handle(app(SmsGateway::class));

        $campaign->refresh();

        $this->assertSame(1, $campaign->accepted_count);
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(1, SmsLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_cancelled_campaign_stops_queued_chunks(): void
    {
        Http::fake();

        $campaign = Campaign::create([
            'name' => 'Test',
            'message' => 'Hello',
            'type' => MessageType::Promotional,
            'status' => CampaignStatus::Queued,
        ]);

        $campaign->cancel();

        (new SendCampaignChunk($campaign->id, ['94772345678'], true))
            ->handle(app(SmsGateway::class));

        Http::assertNothingSent();
    }

    public function test_delivery_rate_is_null_until_a_receipt_arrives(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $campaign = Campaign::create([
            'name' => 'Test',
            'message' => 'Hello',
            'type' => MessageType::Promotional,
            'status' => CampaignStatus::Queued,
        ]);

        (new SendCampaignChunk($campaign->id, ['94772345678'], true))
            ->handle(app(SmsGateway::class));

        // No receipt configured means no delivery data. Reporting 0% here
        // would look like total failure of a campaign that may have worked.
        $this->assertNull($campaign->refresh()->deliveryRate());
        $this->assertSame(1, $campaign->pendingReceiptCount());
    }

    public function test_delivery_rate_is_computed_once_receipts_land(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $campaign = Campaign::create([
            'name' => 'Test',
            'message' => 'Hello',
            'type' => MessageType::Promotional,
            'status' => CampaignStatus::Queued,
            'accepted_count' => 0,
        ]);

        (new SendCampaignChunk($campaign->id, ['94772345678'], true))
            ->handle(app(SmsGateway::class));

        SmsLog::where('campaign_id', $campaign->id)->update([
            'status' => DeliveryStatus::Delivered->value,
        ]);

        $this->assertSame(1.0, $campaign->refresh()->deliveryRate());
    }
}
