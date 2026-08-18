<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Console;

use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Enums\Encoding;
use Illuminate\Console\Command;

/**
 * Cost a message before committing to a campaign.
 *
 * Worth running on any promotional copy before it goes out, because the API
 * gives no warning that a message has become three messages.
 */
class EstimateCommand extends Command
{
    protected $signature = 'dialog-esms:estimate
        {message : The message body, quoted}
        {--recipients=1 : How many people it goes to}
        {--rate= : Cost per message from your Dialog rate card}';

    protected $description = 'Show the encoding, segment count and billable total for a message';

    public function handle(): int
    {
        $message = (string) $this->argument('message');
        $recipients = max(1, (int) $this->option('recipients'));

        $estimate = MessageEstimate::for($message, $recipients);

        $this->newLine();
        $this->line('  ' . $estimate->summary());
        $this->newLine();

        $this->table(['', ''], [
            ['Encoding', $estimate->encoding->label()],
            ['Length', $estimate->length . ' characters'],
            ['Segments', (string) $estimate->segments],
            ['Room left in last segment', (string) $estimate->remainingInSegment],
            ['Recipients', number_format($estimate->recipients)],
            ['Billable messages', number_format($estimate->billableMessages)],
        ]);

        if ($estimate->encoding === Encoding::Ucs2) {
            $this->warn(sprintf(
                'This is Unicode, so each message holds %d characters instead of %d.',
                Encoding::Ucs2->singleLimit(),
                Encoding::Gsm7->singleLimit(),
            ));

            if ($estimate->isAccidentallyUnicode()) {
                $this->newLine();
                $this->line('  Only these characters forced it: ' . implode(' ', $estimate->nonGsmCharacters));
                $this->line('  Replacing them with ASCII equivalents would cut the cost.');

                $cheaper = MessageEstimate::for(
                    str_replace($estimate->nonGsmCharacters, '', $message),
                    $recipients,
                );

                $this->line(sprintf(
                    '  Without them: %d billable message%s instead of %d.',
                    $cheaper->billableMessages,
                    $cheaper->billableMessages === 1 ? '' : 's',
                    $estimate->billableMessages,
                ));
            }
        }

        $rate = $this->option('rate');

        if ($rate !== null) {
            $this->newLine();
            $this->info(sprintf('  Estimated cost: LKR %s', number_format($estimate->costAt((float) $rate), 2)));
            $this->line('  (based on the rate you supplied — the API never returns cost)');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
