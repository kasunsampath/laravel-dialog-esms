<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Console;

use KasunSampath\DialogEsms\Contracts\SmsGateway;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use Illuminate\Console\Command;

/**
 * Sends one real message. Useful as the first thing to run after configuring
 * credentials, because it surfaces a wrong key or an unregistered sender mask
 * immediately rather than during an OTP flow.
 */
class SendTestMessageCommand extends Command
{
    protected $signature = 'dialog-esms:test {phone : Recipient, any Sri Lankan format} {--message=Dialog eSMS test message}';

    protected $description = 'Send a single real test message through Dialog eSMS';

    public function handle(SmsGateway $gateway): int
    {
        $phone = (string) $this->argument('phone');

        if (! $gateway->validate($phone)) {
            $this->error(sprintf('"%s" is not a valid Sri Lankan mobile number.', $phone));

            return self::FAILURE;
        }

        $this->warn('This sends a real message and spends real credit.');

        try {
            $result = $gateway->send($phone, (string) $this->option('message'));
        } catch (DialogEsmsException $e) {
            $this->error($e->getMessage());

            if ($e->isBillingIssue()) {
                $this->line('This is a billing problem, not a configuration one — top up the wallet.');
            }

            return self::FAILURE;
        }

        $this->info('Accepted by Dialog. Local reference: ' . $result->reference);
        $this->line('Acceptance is not delivery — watch for a receipt to confirm it reached the handset.');

        return self::SUCCESS;
    }
}
