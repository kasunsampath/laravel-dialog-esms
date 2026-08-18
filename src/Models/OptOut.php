<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Models;

use CodeRayTech\DialogEsms\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Recipients who have asked not to receive promotional messages.
 *
 * Dialog maintains its own mask-level block list — that is what response code
 * 2009 ("no valid numbers after removing mask-blocked numbers") refers to —
 * but it is opaque: you cannot read it, and a partly blocked campaign still
 * reports success without saying who was dropped. Keeping a local list is the
 * only way to know your own suppression state, and the only way to honour an
 * opt-out that reached you by some other route (a reply, an email, a phone
 * call).
 *
 * Numbers are stored normalised so a request to unsubscribe `0772345678`
 * suppresses `+94772345678` too.
 */
class OptOut extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opted_out_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('dialog-esms.logging.opt_out_table', 'dialog_sms_opt_outs');
    }

    /**
     * Suppress a number. Idempotent — re-recording an opt-out is not an error,
     * and must never be, because unsubscribe links get clicked twice.
     */
    public static function add(string $phone, ?string $reason = null, ?string $scope = null): self
    {
        return static::query()->updateOrCreate(
            [
                'msisdn' => PhoneNumber::normalize($phone),
                'scope' => $scope,
            ],
            [
                'reason' => $reason,
                'opted_out_at' => now(),
            ],
        );
    }

    /**
     * Remove a suppression, for a recipient who has opted back in.
     */
    public static function remove(string $phone, ?string $scope = null): bool
    {
        return static::query()
            ->where('msisdn', PhoneNumber::normalize($phone))
            ->where('scope', $scope)
            ->delete() > 0;
    }

    public static function has(string $phone, ?string $scope = null): bool
    {
        return static::query()
            ->where('msisdn', PhoneNumber::normalize($phone))
            ->forScope($scope)
            ->exists();
    }

    /**
     * Split a recipient list into those who may be messaged and those who may
     * not.
     *
     * Done in one query rather than per number: a campaign of 50,000 would
     * otherwise issue 50,000 lookups.
     *
     * @param  array<int, string>  $numbers  Already normalised.
     * @return array{allowed: array<int, string>, suppressed: array<int, string>}
     */
    public static function filter(array $numbers, ?string $scope = null): array
    {
        if ($numbers === []) {
            return ['allowed' => [], 'suppressed' => []];
        }

        $blocked = static::query()
            ->whereIn('msisdn', $numbers)
            ->forScope($scope)
            ->pluck('msisdn')
            ->all();

        if ($blocked === []) {
            return ['allowed' => $numbers, 'suppressed' => []];
        }

        $blockedMap = array_fill_keys(array_map(strval(...), $blocked), true);
        $allowed = [];
        $suppressed = [];

        foreach ($numbers as $number) {
            if (isset($blockedMap[$number])) {
                $suppressed[] = $number;
            } else {
                $allowed[] = $number;
            }
        }

        return ['allowed' => $allowed, 'suppressed' => $suppressed];
    }

    /**
     * Match a scoped opt-out, and always match a global one.
     *
     * A global opt-out (`scope` null) means "no promotional messages at all",
     * so it has to win regardless of which campaign is asking.
     */
    public function scopeForScope(Builder $query, ?string $scope): Builder
    {
        return $query->where(function (Builder $q) use ($scope): void {
            $q->whereNull('scope');

            if ($scope !== null) {
                $q->orWhere('scope', $scope);
            }
        });
    }
}
