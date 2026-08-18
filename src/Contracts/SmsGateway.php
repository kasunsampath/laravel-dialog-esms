<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Contracts;

use KasunSampath\DialogEsms\Data\Balance;
use KasunSampath\DialogEsms\Data\BulkResult;
use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Data\SmsResult;

interface SmsGateway
{
    /**
     * Send one message.
     *
     * @param  array<string, mixed>  $options  sender_id, notifiable, metadata
     */
    public function send(string $to, string $message, array $options = []): SmsResult;

    /**
     * Send one message to many recipients as a campaign.
     *
     * @param  iterable<string>      $recipients
     * @param  array<string, mixed>  $options
     */
    public function sendBulk(iterable $recipients, string $message, array $options = []): BulkResult;

    /**
     * Read the wallet balance.
     */
    public function balance(): Balance;

    /**
     * Whether a number is a deliverable Sri Lankan mobile.
     */
    public function validate(string $phone): bool;

    /**
     * Segment count and billable total for a message, before sending it.
     */
    public function estimate(string $message, int $recipients = 1): MessageEstimate;
}
