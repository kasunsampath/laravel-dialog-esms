<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Tests\Unit;

use Carbon\CarbonImmutable;
use CodeRayTech\DialogEsms\Support\QuietHours;
use PHPUnit\Framework\TestCase;

class QuietHoursTest extends TestCase
{
    private function window(string $start = '21:00', string $end = '08:00', bool $enabled = true): QuietHours
    {
        return new QuietHours($enabled, $start, $end, 'Asia/Colombo');
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-18 ' . $time, 'Asia/Colombo');
    }

    public function test_late_evening_is_quiet(): void
    {
        $this->assertTrue($this->window()->isQuietAt($this->at('22:30')));
    }

    public function test_the_small_hours_are_quiet(): void
    {
        // The window wraps midnight, which is the case naive comparisons get
        // wrong — 03:00 is not "between 21:00 and 08:00" numerically.
        $this->assertTrue($this->window()->isQuietAt($this->at('03:00')));
    }

    public function test_midday_is_not_quiet(): void
    {
        $this->assertFalse($this->window()->isQuietAt($this->at('12:00')));
    }

    public function test_boundaries(): void
    {
        $window = $this->window();

        $this->assertTrue($window->isQuietAt($this->at('21:00')), 'start is inclusive');
        $this->assertFalse($window->isQuietAt($this->at('08:00')), 'end is exclusive');
        $this->assertTrue($window->isQuietAt($this->at('07:59')));
    }

    public function test_a_same_day_window_does_not_wrap(): void
    {
        $window = $this->window('02:00', '06:00');

        $this->assertTrue($window->isQuietAt($this->at('03:00')));
        $this->assertFalse($window->isQuietAt($this->at('22:00')));
    }

    public function test_disabling_it_makes_every_hour_permitted(): void
    {
        $this->assertFalse($this->window(enabled: false)->isQuietAt($this->at('03:00')));
    }

    public function test_next_permitted_from_the_evening_is_tomorrow_morning(): void
    {
        // 22:30 today must resolve to 08:00 *tomorrow*, not 08:00 today, which
        // is in the past and would fire immediately.
        $next = $this->window()->nextPermittedAfter($this->at('22:30'));

        $this->assertSame('2026-08-19 08:00', $next->format('Y-m-d H:i'));
    }

    public function test_next_permitted_from_the_small_hours_is_this_morning(): void
    {
        $next = $this->window()->nextPermittedAfter($this->at('03:00'));

        $this->assertSame('2026-08-18 08:00', $next->format('Y-m-d H:i'));
    }

    public function test_next_permitted_outside_the_window_is_now(): void
    {
        $moment = $this->at('12:00');

        $this->assertSame(
            $moment->format('Y-m-d H:i'),
            $this->window()->nextPermittedAfter($moment)->format('Y-m-d H:i'),
        );
    }

    public function test_it_describes_itself_for_error_messages(): void
    {
        $this->assertSame('21:00-08:00 Asia/Colombo', $this->window()->describe());
    }
}
