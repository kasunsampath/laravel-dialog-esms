<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Facades;

use KasunSampath\DialogEsms\Campaigns\CampaignBuilder;
use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Data\Balance;
use KasunSampath\DialogEsms\Data\BulkResult;
use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Data\SmsResult;
use KasunSampath\DialogEsms\DialogEsmsClient;
use KasunSampath\DialogEsms\Enums\DeliveryStatus;
use KasunSampath\DialogEsms\Testing\DialogEsmsFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static SmsResult send(string $to, string $message, array $options = [])
 * @method static BulkResult sendBulk(iterable $recipients, string $message, array $options = [])
 * @method static Balance balance()
 * @method static bool validate(string $phone)
 * @method static DeliveryStatus|null statusOf(string $reference)
 * @method static DialogEsmsClient usingSender(string $senderId)
 * @method static MessageEstimate estimate(string $message, int $recipients = 1)
 * @method static CampaignBuilder campaign(string $name)
 *
 * @see DialogEsmsClient
 */
class DialogEsms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DialogEsmsClient::class;
    }

    /**
     * Swap in a fake that records sends instead of calling Dialog.
     */
    public static function fake(): DialogEsmsFake
    {
        $fake = new DialogEsmsFake();

        static::swap($fake);
        app()->instance(SmsGateway::class, $fake);
        app()->instance('dialog-esms', $fake);

        return $fake;
    }
}
