<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Notifications;

use CodeRayTech\DialogEsms\Contracts\SmsGateway;
use CodeRayTech\DialogEsms\Data\SmsResult;
use CodeRayTech\DialogEsms\Exceptions\DialogEsmsException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Laravel notification channel.
 *
 *     public function via($notifiable): array
 *     {
 *         return ['dialog-esms'];
 *     }
 *
 *     public function toDialogEsms($notifiable): DialogEsmsMessage|string
 *     {
 *         return DialogEsmsMessage::make("Your code is {$this->code}");
 *     }
 *
 * The recipient comes from `routeNotificationForDialogEsms()` on the notifiable,
 * falling back to a `phone` or `mobile` attribute.
 */
class DialogEsmsChannel
{
    public function __construct(protected readonly SmsGateway $gateway) {}

    public function send(mixed $notifiable, Notification $notification): ?SmsResult
    {
        $to = $this->routeFor($notifiable, $notification);

        if ($to === null || $to === '') {
            return null;
        }

        /** @var DialogEsmsMessage|string $message */
        $message = $notification->toDialogEsms($notifiable);

        if (is_string($message)) {
            $message = DialogEsmsMessage::make($message);
        }

        try {
            return $this->gateway->send($to, $message->content, array_filter([
                'sender_id' => $message->senderId,
                'notifiable' => is_object($notifiable) ? $notifiable : null,
                'metadata' => $message->metadata,
            ]));
        } catch (DialogEsmsException $e) {
            // A failed SMS must not abort the rest of the notification stack —
            // an OTP that fails to send should still let a mail fallback run.
            // The exception is logged and swallowed; listen for SmsFailed to
            // react to it.
            Log::error('Dialog eSMS notification failed', [
                'recipient' => $to,
                'error' => $e->getMessage(),
                'code' => $e->responseCode?->value,
            ]);

            return null;
        }
    }

    protected function routeFor(mixed $notifiable, Notification $notification): ?string
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $routed = $notifiable->routeNotificationFor('dialogEsms', $notification);

            if (is_string($routed) && $routed !== '') {
                return $routed;
            }
        }

        foreach (['phone', 'mobile', 'phone_number', 'mobile_number'] as $attribute) {
            $value = data_get($notifiable, $attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
