<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Notifications;

class DialogEsmsMessage
{
    /** @var array<string, mixed>|null */
    public ?array $metadata = null;

    public ?string $senderId = null;

    public function __construct(public string $content) {}

    public static function make(string $content): self
    {
        return new self($content);
    }

    /**
     * Override the sender mask for this message. Must already be registered
     * with Dialog — an unregistered mask fails the whole campaign.
     */
    public function from(string $senderId): self
    {
        $this->senderId = $senderId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }
}
