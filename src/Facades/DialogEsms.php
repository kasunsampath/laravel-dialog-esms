<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Facades;

use CodeRayTech\DialogEsms\Campaigns\CampaignBuilder;
use CodeRayTech\DialogEsms\Contracts\SmsGateway;
use CodeRayTech\DialogEsms\Data\Balance;
use CodeRayTech\DialogEsms\Data\BulkResult;
use CodeRayTech\DialogEsms\Data\MessageEstimate;
use CodeRayTech\DialogEsms\Data\SmsResult;
use CodeRayTech\DialogEsms\DialogEsmsClient;
use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Testing\DialogEsmsFake;
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
