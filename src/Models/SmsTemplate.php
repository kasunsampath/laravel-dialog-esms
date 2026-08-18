<?php

declare(strict_types=1);

namespace KasunSampath\DialogEsms\Models;

use KasunSampath\DialogEsms\Data\MessageEstimate;
use KasunSampath\DialogEsms\Enums\MessageType;
use KasunSampath\DialogEsms\Exceptions\DialogEsmsException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable message body with `{placeholder}` substitution.
 *
 * Keeping bodies in the database rather than in code lets non-developers fix a
 * typo without a deploy — which matters for SMS, where a typo is charged for
 * on every send.
 */
class SmsTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'type' => MessageType::class,
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('dialog-esms.logging.template_table', 'dialog_sms_templates');
    }

    /**
     * Fill in the placeholders.
     *
     * Unresolved placeholders are a hard error rather than being left in the
     * text or blanked out. A message reading "Your code is {code}" has already
     * cost money and cannot be recalled, so failing loudly before sending is
     * the cheaper outcome.
     *
     * @param  array<string, string|int|float>  $values
     *
     * @throws DialogEsmsException
     */
    public function render(array $values = []): string
    {
        $body = $this->template;

        foreach ($values as $key => $value) {
            $body = str_replace('{' . $key . '}', (string) $value, $body);
        }

        if (preg_match_all('/\{([a-z0-9_]+)\}/i', $body, $matches)) {
            throw new DialogEsmsException(sprintf(
                'SMS template "%s" still has unresolved placeholders: %s',
                $this->name,
                implode(', ', array_unique($matches[1])),
            ));
        }

        return $body;
    }

    /**
     * Cost of this template once rendered.
     *
     * Worth checking against real values rather than the raw template: a
     * placeholder is short, the value substituted into it may not be, and a
     * template that fits one segment in testing can spill into two in
     * production.
     *
     * @param  array<string, string|int|float>  $values
     */
    public function estimate(array $values = [], int $recipients = 1): MessageEstimate
    {
        return MessageEstimate::for($this->render($values), $recipients);
    }

    /**
     * Look up an active template by name.
     *
     * @throws DialogEsmsException
     */
    public static function named(string $name): self
    {
        $template = static::query()->where('name', $name)->active()->first();

        if ($template === null) {
            throw new DialogEsmsException(sprintf('No active SMS template named "%s".', $name));
        }

        return $template;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePromotional(Builder $query): Builder
    {
        return $query->where('type', MessageType::Promotional->value);
    }
}
