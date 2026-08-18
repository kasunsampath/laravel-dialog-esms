# Changelog

All notable changes to `kasunsampath/laravel-dialog-esms` will be documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Documentation only. Confirmed by measurement against a live account that **Dialog bills per
  segment**: a one-segment message cost LKR 0.57 and a 200-character two-segment message cost
  LKR 1.14, exactly double. This was previously flagged as unverified, with a caution to check your
  rate card. The segmentation maths was never in doubt (GSM 03.38); whether it drove billing was.
  It does.

## [1.0.2] - 2026-08-18

### Added

- `2012` identified and added to `ResponseCode` as `InvalidSenderMask`. Determined by controlled
  test against a live account: with every other parameter byte-identical, `source_address=Jothishya`
  returned `2012` and `source_address=JOTHISHYA` returned `1`. **The sender mask is matched
  case-sensitively**, and a mask differing only in capitalisation is rejected exactly like one that
  was never registered, with nothing in the response pointing at the mask.

  It appears in no published table, including the independent implementation the rest of this table
  was corroborated against.

- `ResponseCode::isConfigurationIssue()`, separating faults the caller can fix (`2006`, `2007`,
  `2012`) from billing problems. Topping up a wallet never clears a bad mask, and the two were
  previously indistinguishable to calling code.

## [1.0.1] - 2026-08-18

### Fixed

- An unrecognised response code was retried as though no response had arrived. `isRetryable()`
  returned true whenever the code could not be mapped to a known case, because the fallback meant
  for transport failures also caught rejections carrying an unknown body. A rejection means the
  request reached Dialog and was refused, so repeating it verbatim only delays the error. Retries
  now hinge on whether a response body was received at all.

  Found when `2012` — observed in production, documented nowhere — was silently attempted three
  times.

### Added

- README notes on `2012`, covering what probing ruled out rather than guessing at a meaning.

## [1.0.0] - 2026-08-18

### Added

- Initial release.
- `DialogEsmsClient` for single and bulk sends against the `message-via-url` API, with correct
  `esmsqk` / `list` / `message` / `source_address` parameter naming.
- `ResponseCode` enum documenting all twelve observed API codes, including the commonly transposed
  `2007` (invalid key) and `2008` (insufficient balance). The table matches the one in
  [MaleeshaUdan/dialog-esms](https://github.com/MaleeshaUdan/dialog-esms), arrived at independently.
- Delivery-receipt endpoint accepting both GET and POST, since Dialog calls it as a GET.
- Receipt correlation by phone-number suffix, with adoption of Dialog's `campaignId` on arrival.
- Sri Lankan phone normalisation and mobile validation (`PhoneNumber`).
- Wallet balance reading, including the pipe-delimited response format.
- Laravel notification channel (`dialog-esms`) and `DialogEsmsMessage`.
- Events: `SmsSent`, `SmsFailed`, `SmsDelivered`, `ReceiptReceived`.
- `DialogEsms::fake()` test double with assertions.
- Artisan commands `dialog-esms:balance` and `dialog-esms:test`.
- Optional persistence of sends and raw receipts.

### Added — marketing

- Message segmentation and cost estimation (`MessageEncoder`, `MessageEstimate`,
  `DialogEsms::estimate()`, `dialog-esms:estimate`). Detects GSM-7 vs UCS-2 and reports billable
  message counts, so a Sinhala or Tamil body cannot silently triple a campaign's cost. Flags messages
  pushed to Unicode by only a handful of stray characters.
- Queued campaigns (`DialogEsms::campaign()`, `SendCampaignChunk`, `Campaign`) with per-chunk retry,
  cancellation, and a cache-backed per-minute rate limit.
- Opt-out suppression (`OptOut`), matched on normalised numbers, with global and per-scope lists.
  Suppressed and invalid recipients are reported separately on the campaign.
- Quiet hours for promotional sending (`QuietHours`), configurable and timezone-aware. Campaigns
  defer to the next permitted moment; immediate sends throw rather than going out silently.
- `MessageType` separating transactional from promotional traffic. Transactional messages bypass
  opt-out filtering and quiet hours so an OTP can never be blocked by a marketing rule; sends default
  to transactional.
- Message templates (`SmsTemplate`) with placeholder substitution, extracted from the original
  Jothishya implementation. Unresolved placeholders raise rather than sending.
- Campaign delivery reporting computed from receipts, returning `null` rather than `0%` when no
  receipt has arrived.

### Changed

- `SmsLog.campaign_id` now refers to a local `Campaign`. Dialog's own identifier, learned from a
  delivery receipt, moved to `dialog_campaign_id`.

### Fixed

- `Campaign.scheduled_for` was stored in the promotional timezone but read back as the application
  timezone, so a campaign deferred to 08:00 Asia/Colombo persisted as 13:30 — five and a half hours
  late. The queued job fired correctly either way; only the recorded schedule was wrong.

[1.0.2]: https://github.com/kasunsampath/laravel-dialog-esms/releases/tag/v1.0.2
[1.0.1]: https://github.com/kasunsampath/laravel-dialog-esms/releases/tag/v1.0.1
[1.0.0]: https://github.com/kasunsampath/laravel-dialog-esms/releases/tag/v1.0.0
