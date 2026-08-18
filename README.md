# Laravel Dialog eSMS

Laravel integration for [Dialog eSMS](https://e-sms.dialog.lk) — Sri Lanka's Dialog Axiata bulk SMS gateway.

Sending, bulk campaigns, wallet balance, delivery receipts, a notification channel, and a test fake.

```php
use CodeRayTech\DialogEsms\Facades\DialogEsms;

DialogEsms::send('0772345678', 'Your verification code is 4821');
```

---

## Why this package exists

Dialog publishes almost nothing about the `message-via-url` API. The behaviour below was
established by observation against the production endpoint, and each item has cost somebody a
debugging session.

Parts of it have been worked out independently before — see [prior art](#prior-art). What is still
undocumented anywhere is the **delivery-receipt half**: receipts arrive as a `GET`, and because the
send response carries no message ID, correlating them has to fall back on the phone number. That is
the piece that silently breaks delivery tracking, and it is the main reason this package exists.

| Behaviour | Consequence if you don't know |
|---|---|
| **Every response is HTTP 200** — success and failure alike | `$response->successful()` returns true for every rejection. Messages silently never send. |
| The outcome is the **response body**, a bare code like `1` or `2007` | Not JSON. Nothing to `->json()`. |
| Parameter names are exactly `esmsqk`, `list`, `message`, `source_address` | A misspelling returns `2007`, which reads as *invalid key* — so you go hunting for a credentials bug that doesn't exist. |
| `2007` = invalid key, **`2008` = out of credit** | These two get transposed constantly. Chasing a balance problem that is really a typo is the single most expensive mistake with this API. |
| The send response contains **no message ID** | You cannot correlate a delivery receipt by ID. It has to be done by phone number. |
| Delivery receipts arrive as a **GET**, not a POST | A `Route::post` webhook answers 405 and every receipt is lost. Nothing looks broken — messages just sit at "sent" forever. |
| Balance returns `1\|1234.5600`, pipe-delimited | Not JSON either. On failure there is no pipe at all. |
| There is **no signing scheme** for the callback | Enabling signature verification with an invented secret rejects every genuine receipt. |

This package encodes all of that so you don't have to rediscover it.

## Prior art

Credit where it's due, and so you can pick the right tool:

- **[MaleeshaUdan/dialog-esms](https://github.com/MaleeshaUdan/dialog-esms)** — plain PHP, no
  Composer package. Independently documents the same endpoints, parameter names and the full
  2001–2011 code table. This package's table was arrived at separately and matches it exactly, which
  is the best corroboration available for an API with no official reference. Use it if you want
  three files and no framework.
- **[erbitron/dialog-esms](https://packagist.org/packages/erbitron/dialog-esms)** — a tidy PHP 8.1+
  client that also works with Laravel. Has the code table and correctly reads the response body
  rather than the HTTP status. Use it if you only need to send messages and check the balance; it is
  smaller and has fewer moving parts than this one.

Reach for this package instead when you need the things those don't cover: receiving and correlating
delivery receipts, phone normalisation and validation, bulk chunking, persistence, a notification
channel, events, a test fake, or the marketing layer — cost estimation, queued campaigns, opt-out
suppression and quiet hours.

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require kasunsampath/laravel-dialog-esms
```

Publish the config:

```bash
php artisan vendor:publish --tag=dialog-esms-config
```

Migrations load automatically. To customise them first:

```bash
php artisan vendor:publish --tag=dialog-esms-migrations
```

Then migrate:

```bash
php artisan migrate
```

## Configuration

```dotenv
DIALOG_ESMS_API_KEY=your-esmsqk-value
DIALOG_ESMS_SENDER_ID=YOURMASK
DIALOG_ESMS_PUSH_URL=https://your-app.test/webhooks/dialog-esms
```

> **The API key is a secret.** It travels as a query parameter, so it must never reach a browser or
> a mobile app. Keep every call server-side. There is no per-device token and no scoped key — anyone
> holding it can drain your wallet and send under your registered mask.

`DIALOG_ESMS_SENDER_ID` must be a mask already registered with Dialog. An unregistered mask fails the
whole campaign, not individual messages.

Verify the setup before wiring it into anything:

```bash
php artisan dialog-esms:balance
```

```bash
php artisan dialog-esms:test 0772345678
```

`dialog-esms:test` sends a real message and spends real credit. It is worth it — a wrong key or an
unregistered mask surfaces here in one second instead of during a login flow.

## Sending

```php
use CodeRayTech\DialogEsms\Facades\DialogEsms;

$result = DialogEsms::send('0772345678', 'Your code is 4821');

$result->successful;   // true
$result->recipient;    // "94772345678" — normalised
$result->reference;    // local reference; Dialog gives you nothing to store
```

Any Sri Lankan format works. `0772345678`, `+94772345678`, `94772345678`, `077 234 5678` and
`077-234-5678` all normalise to the same number.

**Acceptance is not delivery.** A successful `send()` means Dialog queued the campaign. Whether the
handset received it is only ever known from a [delivery receipt](#delivery-receipts).

### Overriding the sender mask

```php
DialogEsms::usingSender('OTHERMASK')->send('0772345678', 'Hello');
```

### Bulk

```php
$result = DialogEsms::sendBulk(
    ['0772345678', '0771234567', '0112345678'],
    'Scheduled maintenance tonight at 11pm.',
);

$result->acceptedCount();  // 2
$result->invalid;          // ['0112345678'] — a landline, filtered locally
$result->successful();     // true
```

Two things happen automatically here that matter:

**Invalid numbers are filtered before sending.** Dialog drops malformed entries from a campaign
without telling you which, so a partly bad list looks like a clean success. This package rejects
them locally and hands them back in `$result->invalid`.

**Long lists are chunked.** Recipients ride in the query string, so a large campaign can exceed the
server's URL limit. Anything over `chunk_size` (default 100) is split into separate campaigns. A
chunk that fails does not abort the others — check `$result->partiallyFailed()` and
`$result->failedChunks()`.

## Cost: the thing that will surprise you

Sinhala and Tamil have no GSM-7 representation, so any message containing them
is sent as UCS-2 — and capacity drops from 160 characters per message to **70**.

| Encoding | Single message | Per part when split |
|---|---|---|
| GSM-7 (English) | 160 chars | 153 |
| UCS-2 (Sinhala/Tamil, emoji, curly quotes) | **70 chars** | **67** |

A 200-character Sinhala promotion is three messages. To 10,000 people that is
**30,000 billable messages**, not 10,000. Neither the request nor the response
mentions this anywhere.

```php
$estimate = DialogEsms::estimate($message, recipients: 10_000);

$estimate->encoding;            // Encoding::Ucs2
$estimate->segments;            // 3
$estimate->billableMessages;    // 30000
$estimate->summary();           // "UCS-2 (Unicode), 200 chars, 3 segments x 10000 recipients = 30000 billable messages"
```

From the command line:

```bash
php artisan dialog-esms:estimate "Sale ends Friday" --recipients=5000 --rate=0.35
```

> The segmentation maths is standard (GSM 03.38). Whether Dialog **bills** per
> segment is not something this package can verify — check your rate card before
> trusting a cost figure.

### The accidental-Unicode trap

One curly quote pasted from a word processor, or one emoji, re-encodes an
entire English message and more than doubles its cost:

```php
$estimate = DialogEsms::estimate("Don’t miss out — sale ends Friday");

$estimate->isAccidentallyUnicode();  // true
$estimate->nonGsmCharacters;         // ['’', '—']
```

`dialog-esms:estimate` prints these and shows what the message would cost
without them. Genuine Sinhala is *not* flagged — there is nothing to fix there.

### Balance

```php
$balance = DialogEsms::balance();

$balance->available;        // true
$balance->amount;           // 1234.56
$balance->formatted();      // "LKR 1,234.56"
$balance->isBelow(500.0);   // alerting threshold
```

### Validation

```php
DialogEsms::validate('0772345678');   // true
DialogEsms::validate('0112345678');   // false — landline, eSMS cannot deliver
```

## Marketing campaigns

`sendBulk()` is synchronous and fine for a few hundred recipients. Beyond that,
use a campaign: chunks go out as queued jobs, so a large run is rate limited,
survives a worker restart, and can be cancelled halfway.

```php
use CodeRayTech\DialogEsms\Facades\DialogEsms;

$campaign = DialogEsms::campaign('October flash sale')
    ->message('Sale ends Friday. Reply STOP to unsubscribe.')
    ->to($numbers)
    ->promotional()
    ->dispatch();
```

Cost it first without sending or storing anything:

```php
DialogEsms::campaign('October flash sale')
    ->message($body)
    ->to($numbers)
    ->promotional()
    ->estimate();     // recipient count already reflects opt-outs and invalid numbers
```

### Transactional vs promotional

This is the distinction that keeps marketing rules away from your login flow.

|  | Transactional | Promotional |
|---|---|---|
| Opt-out list | ignored | **enforced** |
| Quiet hours | ignored | **enforced** |
| Typical use | OTP, receipts, alerts | offers, newsletters |

**Sends default to transactional.** That is the *less* safe default in the
abstract, and it is deliberate: defaulting to promotional would have silently
subjected every existing OTP call to opt-out filtering the moment this feature
landed. Marketing must be declared:

```php
DialogEsms::send($phone, $body, ['message_type' => MessageType::Promotional]);
```

Do not mark a promotion transactional to slip it past a suppression list. That
is a compliance problem, not a shortcut.

### Opt-outs

```php
use CodeRayTech\DialogEsms\Models\OptOut;

OptOut::add('0772345678', reason: 'replied STOP');
OptOut::add('0772345678', scope: 'newsletter');   // this list only
OptOut::remove('0772345678');

OptOut::has('+94772345678');   // true — matching is on the normalised number
```

A scopeless opt-out is global and wins over every scope. Numbers are stored
normalised, so an opt-out recorded as `0772345678` also suppresses
`+94772345678`.

Suppressions are reported rather than hidden:

```php
$campaign->suppressed_count;     // 143
$campaign->suppressed_numbers;   // the actual list, for answering "why didn't X get it?"
$campaign->invalid_count;        // separate: malformed numbers and landlines
```

Dialog keeps its own mask-level block list — that is what response code `2009`
refers to — but it is opaque and tells you nothing about who was dropped. The
local list is the only suppression state you can actually inspect.

### Quiet hours

A promotional campaign built at 23:30 is queued for the next permitted moment
rather than sent at 23:30 or silently discarded. A transactional one is not
delayed.

```dotenv
DIALOG_ESMS_QUIET_START=21:00
DIALOG_ESMS_QUIET_END=08:00
DIALOG_ESMS_TIMEZONE=Asia/Colombo
```

An immediate `send()` marked promotional inside the window throws instead,
naming the next permitted time — silence would be worse than an error on the
immediate path.

> **The default window is a conservative guess, not a legal citation.** Confirm
> the permitted hours and consent requirements for promotional SMS with Dialog
> and the TRCSL. This package enforces whatever window you configure; it does
> not know the law.

### Rate limiting

```dotenv
DIALOG_ESMS_RATE_LIMIT=60      # messages per minute; 0 disables
DIALOG_ESMS_QUEUE=sms
```

Enforced across workers via the cache, so a shared store (Redis, Memcached,
database) is required for it to mean anything with more than one worker. With
the `array` driver each process gets its own private allowance.

### Reporting

Delivery figures come from receipts, not from the send response:

```php
$campaign->report();
// ['delivered' => 8912, 'failed' => 47, 'awaiting_receipt' => 1041, 'delivery_rate' => 89.1, ...]
```

`deliveryRate()` returns `null` — not `0` — when no receipt has ever arrived.
That almost always means the push URL is unset or unreachable, not that the
campaign failed, and reporting 0% would send you debugging the wrong thing.

Cancel a running campaign; queued chunks check before sending:

```php
$campaign->cancel();
```

## Templates

```php
use CodeRayTech\DialogEsms\Models\SmsTemplate;

SmsTemplate::create([
    'name' => 'otp',
    'label' => 'OTP verification',
    'template' => 'Welcome to {app_name}, {code} is your verification code',
    'type' => MessageType::Transactional,
    'variables' => ['app_name', 'code'],
]);

SmsTemplate::named('otp')->render(['app_name' => 'Jothishya', 'code' => '4821']);
```

An unresolved placeholder is a hard error, not a blank. A message reading
"Your code is {code}" has already been paid for and cannot be recalled.

Templates carry their own type, so a template marked promotional stays
promotional wherever it is used:

```php
DialogEsms::campaign('Weekly offers')->template('weekly_offers', ['name' => $name])->to($numbers)->dispatch();
```

Check cost against real values, not the raw template — a short placeholder can
hold a long value:

```php
SmsTemplate::named('order_confirmed')->estimate(['ref' => $ref], recipients: 500);
```

## Error handling

Every failure throws `DialogEsmsException`. Branch on the code, never on the message text:

```php
use CodeRayTech\DialogEsms\Exceptions\DialogEsmsException;
use CodeRayTech\DialogEsms\Enums\ResponseCode;

try {
    DialogEsms::send($phone, $message);
} catch (DialogEsmsException $e) {
    if ($e->isBillingIssue()) {
        // Out of credit. Alert finance — retrying will not help.
    }

    if ($e->responseCode === ResponseCode::InvalidKey) {
        // Wrong esmsqk value — or a misspelled parameter name.
    }

    if ($e->isRetryable()) {
        // Transient. Worth another attempt.
    }
}
```

### Response codes

| Code | Meaning |
|---|---|
| `1` | Success |
| `2001` | Error during campaign creation |
| `2002` | Bad request |
| `2003` | Empty number list |
| `2004` | Empty message body |
| `2005` | Invalid number list format |
| `2006` | Not eligible to send via GET (your admin has not granted this access level) |
| `2007` | **Invalid key** — wrong `esmsqk`, *or* a misspelled parameter name |
| `2008` | **Insufficient balance** — empty wallet or exhausted package |
| `2009` | No valid numbers left after mask-blocked numbers were removed |
| `2010` | Not eligible to consume packaging |
| `2011` | Transactional error (transient — retried automatically) |

Unknown codes are never guessed at. They surface as `Unknown Dialog response (code: X)` and are
stored verbatim.

### Retries

Only transient codes (`2001`, `2011`) and transport failures are retried. A rejected request fails
identically forever, so retrying it only burns time — the package doesn't.

## Delivery receipts

The package registers a route at `webhooks/dialog-esms` accepting **both GET and POST**. Point
`DIALOG_ESMS_PUSH_URL` at it:

```dotenv
DIALOG_ESMS_PUSH_URL=https://your-app.test/webhooks/dialog-esms
```

The URL must be publicly reachable over HTTPS. Without it, no receipt is ever sent and every message
stays at `sent` permanently.

What Dialog actually sends, observed in production:

```http
GET /webhooks/dialog-esms?campaignId=123456789&msisdn=94772345678&status=1
User-Agent: Java/1.8.0_492
```

`status=1` means delivered. No other value has been observed, so anything unrecognised is left at
`sent` — the package will not fabricate a delivery or a failure from a token it doesn't know.

### How correlation works

Dialog never sees the reference this package generates, and the receipt carries their own
`campaignId` instead — so at send time there is no shared identifier at all. Receipts are matched on
the **last nine digits of the phone number**, which makes every spelling line up. When a receipt
does arrive, its `campaignId` is adopted onto the log: that's the ID searchable in Dialog's portal.

Every callback is stored in `dialog_sms_webhooks`, including ones that can't be parsed or matched,
and the endpoint always answers `200`. That is deliberate — a 4xx would make Dialog retry or drop the
callback, and the stored payloads are the only record of what the format actually is. Watch for
uncorrelated receipts as an early warning that the format changed:

```php
use CodeRayTech\DialogEsms\Models\SmsWebhook;

SmsWebhook::uncorrelated()->latest()->get();
```

### Signature verification

Off by default, because Dialog has published no signing scheme. Only set
`DIALOG_ESMS_WEBHOOK_SECRET` if they give you one in writing — a placeholder value rejects every
genuine receipt.

## Events

```php
use CodeRayTech\DialogEsms\Events\{SmsSent, SmsFailed, SmsDelivered, ReceiptReceived};
```

| Event | Fired when |
|---|---|
| `SmsSent` | Dialog accepted the campaign (not delivery) |
| `SmsFailed` | Dialog rejected it, or it never reached them |
| `SmsDelivered` | A receipt confirmed handset delivery |
| `ReceiptReceived` | Any callback arrived, correlated or not |

Alerting on an empty wallet:

```php
Event::listen(function (SmsFailed $event) {
    if ($event->exception->isBillingIssue()) {
        Notification::route('mail', 'ops@example.com')->notify(new SmsCreditExhausted());
    }
});
```

## Notification channel

```php
use CodeRayTech\DialogEsms\Notifications\DialogEsmsMessage;

class VerificationCode extends Notification
{
    public function __construct(private string $code) {}

    public function via($notifiable): array
    {
        return ['dialog-esms'];
    }

    public function toDialogEsms($notifiable): DialogEsmsMessage
    {
        return DialogEsmsMessage::make("Your code is {$this->code}");
    }
}
```

The recipient comes from `routeNotificationForDialogEsms()` on the notifiable, falling back to a
`phone`, `mobile`, `phone_number` or `mobile_number` attribute:

```php
class User extends Authenticatable
{
    public function routeNotificationForDialogEsms(): string
    {
        return $this->mobile;
    }
}
```

A send failure is logged and swallowed rather than thrown, so a failed SMS doesn't abort the rest of
the notification stack. Listen for `SmsFailed` to react to it.

## Testing

```php
use CodeRayTech\DialogEsms\Facades\DialogEsms;

public function test_it_sends_a_verification_code(): void
{
    $fake = DialogEsms::fake();

    $this->post('/register', ['mobile' => '0772345678']);

    $fake->assertSentTo('0772345678', 'Your code is');
    $fake->assertSentCount(1);
}
```

Available assertions: `assertSent()`, `assertSentTo()`, `assertSentCount()`, `assertNothingSent()`.

Recipient validation still runs against the fake, so a test using a badly formatted number fails the
same way production would. You can also simulate failures:

```php
DialogEsms::fake()->shouldFailWith(ResponseCode::InsufficientBalance);
```

To disable sending outright in an environment:

```dotenv
DIALOG_ESMS_ENABLED=false
```

Sends then return `$result->skipped === true` rather than throwing.

## Querying logs

```php
use CodeRayTech\DialogEsms\Models\SmsLog;

SmsLog::delivered()->recent(7)->count();
SmsLog::failed()->recent(30)->get();

// Sent, but no receipt ever came back. A growing number here usually means
// the push URL is unreachable, not that messages are failing.
SmsLog::awaitingReceipt()->where('created_at', '<', now()->subHours(6))->get();
```

Set `logging.enabled` to `false` in the config to skip persistence entirely; the package then also
skips both migrations.

## Troubleshooting

**Everything returns 2007 and the key is definitely right.** Check the parameter names. A typo in
`list` or `source_address` produces the same code as a bad key.

**Messages send but always stay at "sent".** Either `DIALOG_ESMS_PUSH_URL` isn't set, or it isn't
publicly reachable, or the route is POST-only somewhere in front of the app. Check
`SmsWebhook::count()` — zero means no callback ever arrived.

**Receipts arrive but nothing correlates.** Inspect the stored payloads; Dialog changed the format.
`SmsWebhook::uncorrelated()->latest()->first()->payload` shows what they're actually sending now.

**2006 on every send.** Your Dialog account administrator hasn't granted GET-request access. That's
a portal setting, not a code problem.

## A note on mobile apps

There is no Flutter or client-side package for this, deliberately. The `esmsqk` key authenticates by
itself with no scoping and no per-device token, so shipping it in an app hands anyone who unzips the
bundle your full SMS wallet. Call this package from your backend and expose your own authenticated
endpoint to the app.

## License

MIT. See [LICENSE.md](LICENSE.md).
