<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Window during which promotional messages are held back.
 *
 * Marketing SMS at 3am generates complaints and opt-outs, and in most
 * jurisdictions there are rules about it. This package enforces a configurable
 * window rather than any particular legal standard — **confirm the actual
 * permitted hours for promotional SMS with Dialog and the TRCSL before relying
 * on the default**, which is a conservative guess, not a citation.
 *
 * Transactional messages are never held: an OTP at 3am is one the recipient
 * just asked for.
 */
final class QuietHours
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $start,
        private readonly string $end,
        private readonly string $timezone,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('dialog-esms.promotional.quiet_hours.enabled', true),
            start: (string) config('dialog-esms.promotional.quiet_hours.start', '21:00'),
            end: (string) config('dialog-esms.promotional.quiet_hours.end', '08:00'),
            timezone: (string) config('dialog-esms.promotional.timezone', 'Asia/Colombo'),
        );
    }

    /**
     * Whether the given moment falls inside the quiet window.
     *
     * The window normally wraps midnight (21:00 to 08:00), so the comparison
     * flips depending on whether start is later than end. A same-day window
     * such as 02:00 to 06:00 works too.
     */
    public function isQuietAt(?CarbonInterface $moment = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $now = CarbonImmutable::instance($moment ?? CarbonImmutable::now())->setTimezone($this->timezone);
        $current = $now->format('H:i');

        if ($this->start === $this->end) {
            return false;
        }

        if ($this->start < $this->end) {
            return $current >= $this->start && $current < $this->end;
        }

        // Wraps midnight.
        return $current >= $this->start || $current < $this->end;
    }

    /**
     * The next moment at which sending is permitted.
     *
     * Returns the given moment unchanged when it is already outside the
     * window, so callers can use it unconditionally as a delay target.
     */
    public function nextPermittedAfter(?CarbonInterface $moment = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($moment ?? CarbonImmutable::now())->setTimezone($this->timezone);

        if (! $this->isQuietAt($now)) {
            return $now;
        }

        [$hour, $minute] = array_map(intval(...), explode(':', $this->end));

        $target = $now->setTime($hour, $minute);

        // When the window wraps midnight and we are in the evening portion of
        // it, the end time is tomorrow morning, not this morning.
        return $target->lessThanOrEqualTo($now) ? $target->addDay() : $target;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function describe(): string
    {
        return sprintf('%s-%s %s', $this->start, $this->end, $this->timezone);
    }
}
