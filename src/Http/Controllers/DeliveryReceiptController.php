<?php

declare(strict_types=1);

namespace CodeRayTech\DialogEsms\Http\Controllers;

use CodeRayTech\DialogEsms\Enums\DeliveryStatus;
use CodeRayTech\DialogEsms\Events\ReceiptReceived;
use CodeRayTech\DialogEsms\Events\SmsDelivered;
use CodeRayTech\DialogEsms\Models\SmsLog;
use CodeRayTech\DialogEsms\Models\SmsWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Dialog eSMS delivery receipts.
 *
 * Dialog documents nothing about this callback. What follows was observed
 * against production and is the reason the handler is shaped the way it is:
 *
 *     GET /webhooks/dialog-esms?campaignId=123456789&msisdn=94772345678&status=1
 *     User-Agent: Java/1.8.0_492
 *
 * It arrives as a **GET with query parameters**, not the POST that "webhook"
 * implies. A POST-only route answers 405 and every receipt is lost without a
 * trace — the sends simply stay at "sent" forever and nothing looks broken.
 * The registered route therefore accepts both methods.
 *
 * The handler is deliberately permissive in two further ways. It stores every
 * callback, including ones it cannot parse or match to a send, because the
 * stored payloads are the only documentation of the format that exists. And it
 * always answers 200: rejecting an unexpected shape with a 4xx would make
 * Dialog retry or drop the callback, and would hide a format change instead of
 * recording it.
 */
class DeliveryReceiptController
{
    public function __invoke(Request $request): JsonResponse
    {
        // Signature checking runs only when a secret is configured. Dialog has
        // published no signing scheme for this callback, so a placeholder
        // secret here rejects every genuine receipt.
        $secret = config('dialog-esms.webhook_secret');

        if (is_string($secret) && $secret !== '' && ! $this->signatureIsValid($request, $secret)) {
            Log::warning('Dialog eSMS receipt rejected: bad signature');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // Query parameters, form body and JSON body are merged because Dialog
        // has used the query string in every observation so far, but nothing
        // guarantees it stays that way.
        $payload = array_merge($request->query(), $request->all());

        $campaignId = $this->extract($payload, ['campaignId', 'campaign_id', 'messageId', 'message_id', 'msgId', 'requestId', 'id']);
        $msisdn = $this->extract($payload, ['msisdn', 'number', 'to', 'destination', 'destinationAddress', 'mobile', 'phone', 'recipient']);
        $rawStatus = $this->extract($payload, ['status', 'deliveryStatus', 'delivery_status', 'dlr_status', 'state', 'result']);

        $log = SmsLog::correlate($campaignId, $msisdn);
        $mapped = DeliveryStatus::fromReceipt($rawStatus);

        $webhook = SmsWebhook::create([
            'sms_log_id' => $log?->id,
            'dialog_campaign_id' => $campaignId,
            'msisdn' => $msisdn,
            'raw_status' => $rawStatus,
            'mapped_status' => $mapped->value,
            'payload' => $payload,
            'http_method' => $request->method(),
            'received_at' => now(),
        ]);

        if ($log === null) {
            Log::warning('Dialog eSMS receipt could not be correlated', [
                'campaign_id' => $campaignId,
                'msisdn' => $msisdn,
                'payload_keys' => array_keys($payload),
            ]);
        } elseif ($rawStatus !== null) {
            $this->applyToLog($log, $campaignId, $mapped, $payload);
        }

        ReceiptReceived::dispatch($webhook, $log !== null);

        if ($log !== null && $mapped === DeliveryStatus::Delivered) {
            SmsDelivered::dispatch($log, (string) $msisdn);
        }

        return response()->json(['received' => true, 'id' => $webhook->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyToLog(SmsLog $log, ?string $campaignId, DeliveryStatus $mapped, array $payload): void
    {
        $update = ['status' => $mapped];

        // Adopt Dialog's campaign id the first time we see it. It is the only
        // identifier that can be looked up in their portal, and the send call
        // never gave us one.
        if ($campaignId !== null && $log->dialog_campaign_id === null) {
            $update['dialog_campaign_id'] = $campaignId;
        }

        if ($mapped === DeliveryStatus::Delivered) {
            $update['delivered_at'] = now();
        } elseif ($mapped === DeliveryStatus::Failed) {
            $update['failed_at'] = now();
            $update['error'] = $this->extract($payload, ['error_message', 'errorMessage', 'reason'])
                ?? 'Reported undelivered by Dialog';
        }

        $log->update($update);
    }

    /**
     * First non-empty scalar among the candidate keys.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>    $keys
     */
    protected function extract(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    protected function signatureIsValid(Request $request, string $secret): bool
    {
        $header = (string) config('dialog-esms.webhook.signature_header', 'X-Dialog-Signature');
        $signature = $request->header($header);

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
