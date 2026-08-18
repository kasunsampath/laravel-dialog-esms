<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Console;

use KasunSampath\DialogEsms\Contracts\SmsGateway;
use Illuminate\Console\Command;

class CheckBalanceCommand extends Command
{
    protected $signature = 'dialog-esms:balance {--fail-below= : Exit non-zero when the balance is under this amount}';

    protected $description = 'Show the Dialog eSMS wallet balance';

    public function handle(SmsGateway $gateway): int
    {
        $balance = $gateway->balance();

        if (! $balance->available) {
            $this->error('Balance unavailable: ' . $balance->error);

            return self::FAILURE;
        }

        $this->info('Dialog eSMS balance: ' . $balance->formatted());

        $threshold = $this->option('fail-below');

        if ($threshold !== null && $balance->isBelow((float) $threshold)) {
            $this->warn(sprintf('Below the %s threshold.', $threshold));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
