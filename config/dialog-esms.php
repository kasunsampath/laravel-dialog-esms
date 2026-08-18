<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The API key is the `esmsqk` value from your Dialog eSMS portal. It is
    | passed as a query parameter on every request, so it must never reach a
    | browser or a mobile app — keep all calls server-side.
    |
    | `sender_id` is the alphanumeric mask registered with Dialog. An
    | unregistered mask is rejected at the campaign level, not per message.
    |
    */

    'base_url' => env('DIALOG_ESMS_BASE_URL', 'https://e-sms.dialog.lk/api/v1/message-via-url'),

    'api_key' => env('DIALOG_ESMS_API_KEY'),

    'sender_id' => env('DIALOG_ESMS_SENDER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Delivery receipts
    |--------------------------------------------------------------------------
    |
    | Dialog sends delivery receipts to `push_url`, passed on each send as
    | `push_notification_url`. Leave it null to disable receipts entirely —
    | messages then remain at "sent" forever, because the send call itself
    | never reports delivery.
    |
    | The URL must be publicly reachable. Dialog calls it as a **GET** with
    | query parameters (observed in production; they document no schema), so
    | the package route accepts both GET and POST. A POST-only route answers
    | 405 and every receipt is lost silently.
    |
    | `webhook_secret` is off by default on purpose. Dialog has published no
    | signing scheme for this callback, so enabling verification with an
    | invented secret rejects every genuine receipt. Only set this if Dialog
    | give you a scheme in writing.
    |
    */

    'push_url' => env('DIALOG_ESMS_PUSH_URL'),

    'webhook_secret' => env('DIALOG_ESMS_WEBHOOK_SECRET'),

    'webhook' => [
        'enabled' => env('DIALOG_ESMS_WEBHOOK_ENABLED', true),

        // Path the package registers for receipts. Must match `push_url`.
        'path' => env('DIALOG_ESMS_WEBHOOK_PATH', 'webhooks/dialog-esms'),

        'middleware' => ['api'],

        // Header carrying the HMAC-SHA256 signature, if you ever get one.
        'signature_header' => 'X-Dialog-Signature',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | Retries apply only to transport failures and to codes the API marks
    | retryable. A rejected request (bad key, empty body, malformed numbers)
    | fails identically on every attempt, so it is never retried.
    |
    */

    'timeout' => env('DIALOG_ESMS_TIMEOUT', 30),

    'bulk_timeout' => env('DIALOG_ESMS_BULK_TIMEOUT', 60),

    'retries' => env('DIALOG_ESMS_RETRIES', 2),

    'retry_delay' => env('DIALOG_ESMS_RETRY_DELAY', 500),

    /*
    |--------------------------------------------------------------------------
    | Bulk sending
    |--------------------------------------------------------------------------
    |
    | Recipients travel in the query string as a comma-separated list, so a
    | large campaign can exceed the server's URL length limit. The client
    | splits anything longer into chunks and sends them as separate campaigns.
    |
    */

    'chunk_size' => env('DIALOG_ESMS_CHUNK_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, every send and every receipt is persisted, which is what
    | makes delivery-receipt correlation possible. Disable it if you only need
    | fire-and-forget sending; the package then skips both migrations.
    |
    */

    'logging' => [
        'enabled' => env('DIALOG_ESMS_LOGGING', true),

        'log_table' => 'dialog_sms_logs',

        'webhook_table' => 'dialog_sms_webhooks',

        'campaign_table' => 'dialog_sms_campaigns',

        'opt_out_table' => 'dialog_sms_opt_outs',

        'template_table' => 'dialog_sms_templates',

        // Store message bodies. Turn off where messages carry personal data
        // you would rather not retain.
        'store_message_body' => env('DIALOG_ESMS_STORE_BODY', true),

        // Channel for the package's own diagnostic logging. Null uses default.
        'channel' => env('DIALOG_ESMS_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued campaigns
    |--------------------------------------------------------------------------
    |
    | Campaigns are sent chunk by chunk as queued jobs, so a large run survives
    | a worker restart and can be paced. Set `rate_limit_per_minute` to 0 to
    | send as fast as the queue drains.
    |
    | The rate limit is enforced across all workers via the cache, so a shared
    | cache store (Redis, Memcached, database) is required for it to mean
    | anything when you run more than one worker. With the `array` driver each
    | process gets its own private allowance.
    |
    */

    'queue' => [
        'name' => env('DIALOG_ESMS_QUEUE', 'default'),

        'rate_limit_per_minute' => env('DIALOG_ESMS_RATE_LIMIT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotional sending
    |--------------------------------------------------------------------------
    |
    | These rules apply only to messages explicitly marked promotional.
    | Transactional messages — OTPs, receipts, alerts — bypass them, so a
    | marketing opt-out can never stop someone logging in.
    |
    | IMPORTANT: the quiet-hours window below is a conservative default, not a
    | legal citation. Confirm the permitted hours and consent requirements for
    | promotional SMS with Dialog and the TRCSL before relying on it.
    |
    */

    'promotional' => [
        'timezone' => env('DIALOG_ESMS_TIMEZONE', 'Asia/Colombo'),

        'quiet_hours' => [
            'enabled' => env('DIALOG_ESMS_QUIET_HOURS', true),
            'start' => env('DIALOG_ESMS_QUIET_START', '21:00'),
            'end' => env('DIALOG_ESMS_QUIET_END', '08:00'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global kill switch
    |--------------------------------------------------------------------------
    |
    | When false, sends are skipped and reported as such instead of hitting
    | the API. Useful in staging so tests never spend real credit.
    |
    */

    'enabled' => env('DIALOG_ESMS_ENABLED', true),

];
