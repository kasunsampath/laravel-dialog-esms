<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Data;

use KasunSampath\DialogEsms\Enums\Encoding;
use KasunSampath\DialogEsms\Support\MessageEncoder;
use JsonSerializable;

/**
 * What a message will cost before you send it.
 *
 * `billableMessages` is the number that matters: recipients multiplied by
 * segments. Sending 10,000 people a 200-character Sinhala message is 30,000
 * billable messages, and neither the API request nor its response mentions
 * this anywhere.
 */
final readonly class MessageEstimate implements JsonSerializable
{
    /**
     * @param  array<int, string>  $nonGsmCharacters
     */
    public function __construct(
        public Encoding $encoding,
        public int $length,
        public int $segments,
        public int $recipients,
        public int $billableMessages,
        public int $remainingInSegment,
        public array $nonGsmCharacters = [],
    ) {}

    public static function for(string $message, int $recipients = 1): self
    {
        $encoding = MessageEncoder::detect($message);
        $segments = MessageEncoder::segments($message, $encoding);

        return new self(
            encoding: $encoding,
            length: MessageEncoder::length($message, $encoding),
            segments: $segments,
            recipients: $recipients,
            billableMessages: $segments * $recipients,
            remainingInSegment: MessageEncoder::remainingInSegment($message, $encoding),
            nonGsmCharacters: $encoding === Encoding::Ucs2
                ? MessageEncoder::nonGsmCharacters($message)
                : [],
        );
    }

    /**
     * Whether the message is Unicode purely because of a few stray characters.
     *
     * True means the text is essentially Latin and a find-and-replace on a
     * handful of smart quotes or emoji would more than halve the cost. False
     * means it is genuinely Sinhala, Tamil or similar, and UCS-2 is
     * unavoidable.
     */
    public function isAccidentallyUnicode(): bool
    {
        return $this->encoding === Encoding::Ucs2
            && $this->nonGsmCharacters !== []
            && count($this->nonGsmCharacters) <= 5;
    }

    /**
     * Multiply out against a per-message rate from your Dialog rate card.
     *
     * The API never returns cost, so the rate has to come from you.
     */
    public function costAt(float $ratePerMessage): float
    {
        return $this->billableMessages * $ratePerMessage;
    }

    public function summary(): string
    {
        return sprintf(
            '%s, %d chars, %d segment%s x %d recipient%s = %d billable message%s',
            $this->encoding->label(),
            $this->length,
            $this->segments,
            $this->segments === 1 ? '' : 's',
            $this->recipients,
            $this->recipients === 1 ? '' : 's',
            $this->billableMessages,
            $this->billableMessages === 1 ? '' : 's',
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding->value,
            'length' => $this->length,
            'segments' => $this->segments,
            'recipients' => $this->recipients,
            'billable_messages' => $this->billableMessages,
            'remaining_in_segment' => $this->remainingInSegment,
            'non_gsm_characters' => $this->nonGsmCharacters,
            'accidentally_unicode' => $this->isAccidentallyUnicode(),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
