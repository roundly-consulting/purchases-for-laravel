# Changelog

All notable changes to `purchases-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `FakeResult::purchase()`, `subscription()` and `refund()` take an optional `occurredAt`, so a
  test can order fake deliveries.
- `Purchases::assertSubscriptionRecorded()` on the fake: a subscription result arrived through
  `handle()`, `sync()` or `replay()`, whatever it did — what `assertSubscriptionStarted()` used to
  check.

### Fixed

- `purchases:install --providers` appends Google's push authentication keys
  (`PURCHASES_GOOGLE_PUSH_AUDIENCE`, `PURCHASES_GOOGLE_PUSH_SERVICE_ACCOUNT`,
  `PURCHASES_GOOGLE_PUSH_TOKEN`), and `purchases:providers` reports Google as configured only once
  its pushes can be authenticated (a URL token, the OIDC pair, or authentication switched off).
  Push authentication is fail-closed, so every RTDN was refused while the command said "yes".
- **Behaviour change:** a Google Real-time Developer Notification for another app (its
  `packageName` is not `purchases.settings.google.package_name`, as on a shared Pub/Sub topic) is
  audited as information and answered 2xx — never applied. Another app's voided purchase used to
  be recorded as a refund here, and its subscription RTDNs were looked up under this app and failed
  with a 500 that Pub/Sub redelivered for days. `PayloadFactory`'s Google RTDNs now name the
  configured package.
- **Behaviour change:** `FakeResult` builds results the way the real providers do — one id as
  both the provider and the transaction id (they were two different `uniqid()`s), and an
  `occurredAt` (now, unless given), so event ordering applies to faked deliveries too.
- Stripe and Google refunds record a `refunded_at`: when the provider names no refund date (only
  Apple does), it is the time of the refund event. It was always `NULL` for them.
- Two first deliveries of the same purchase, subscription or refund arriving at once no longer
  fail one of them on MySQL. Its key lock takes a gap lock on a row that does not exist yet, so
  both inserts deadlocked (error 1213) and one webhook — or queued job — failed until the store
  redelivered it or you ran `purchases:replay`. The recording transaction is now retried.
- `purchases:providers` reports Stripe as configured only when both `PURCHASES_STRIPE_SECRET` and
  `PURCHASES_STRIPE_WEBHOOK_SECRET` are set, and the README names both. With the webhook secret
  alone, every `payment_intent.*` webhook on a current API version was refused (the secret key
  answers which invoice a payment belongs to) while the command said "yes".
- Re-running `purchases:install --providers` no longer appends a key the `.env` already defines.
  Its blank copy came later in the file and won, so a second run wiped configured secrets (for
  example `PURCHASES_STRIPE_WEBHOOK_SECRET`) and flipped `PURCHASES_APPLE_SANDBOX` back to
  `false`.
- **Behaviour change:** a one-off Stripe invoice recorded from `invoice.paid` now carries the
  PaymentIntent that paid it as its `transaction_id` (still keyed on the invoice), so its
  `charge.refunded` and `charge.dispute.*` link to it and flip it to `Refunded`. They used to be
  recorded unlinked and leave the purchase `Completed`. On API versions since 2025-03-31 the
  PaymentIntent is read from Stripe's Invoice Payments API, which needs
  `PURCHASES_STRIPE_SECRET`.
- Google Play acknowledgements send a JSON object (`{}`) as the request body. They sent `[]`,
  which Google rejects with 400, so `product()`, `subscription()` and `callbackResult()` threw
  after a successful verification and purchases stayed unacknowledged (Google refunds those after
  three days).
- Provider dates (`active_from`, `trial_ends_at`, `ends_at`, `refunded_at`) are stored on their
  real instant when `app.timezone` is not UTC. They used to shift by the timezone offset, so on a
  Central European host a subscription expired an hour or two early (and west of UTC, late).
- **Behaviour change:** `Purchases::assertSubscriptionStarted()` now passes only when the
  recording pipeline really fired `SubscriptionStarted` (with or without `Event::fake()`). It
  used to pass for any subscription result — a canceled one or a renewal included. Tests that
  meant "a subscription result arrived" should switch to `assertSubscriptionRecorded()`.

- **Behaviour change:** a subscription created before it was paid for (Stripe `incomplete`,
  Google `SUBSCRIPTION_STATE_PENDING`) now fires `SubscriptionStarted` when it first becomes
  active, instead of `SubscriptionRenewed` — so the owner-linking listener runs for 3D Secure and
  `default_incomplete` Stripe subscriptions. A paused Google subscription that resumes still
  fires `SubscriptionRenewed`.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- One API for Apple App Store, Google Play and Stripe purchases and subscriptions through the
  `Purchases` facade: `result()` verifies and decodes, `handle()` also persists and fires events.
- `Purchases::sync($result)` persists a `ProviderResult` you already hold (a receipt your app
  verified, a backfill) exactly like a webhook — audited, recorded, events fired — always
  synchronously. Its audit row is truthful: `signature_verified = false` with origin `host`
  (`NotificationOrigin`, read with `PurchaseNotification::origin()`).
- `Purchases::replay($notification)` re-runs one stored audit notification (model or id) and
  marks it processed; `purchases:replay` now replays through it. Host-synced rows replay;
  provider notifications that failed verification, deleted and unrebuildable ones are refused
  with `InvalidProviderNotificationException`.
- `Purchases::for($owner)` — one owner's `purchases()`, `subscriptions()`,
  `activeSubscription()` and `subscribedTo()`, scoped to that owner's morph type and key, no
  trait needed.
- The facade root `PurchasesManager` for dependency injection, and the actions behind it
  (`HandleProviderResultAction`, `SyncProviderResultAction`, `ReplayProviderNotificationAction`).
- A provider-agnostic `ProviderResult` contract, so host code never branches on the store.
- Native verification with no third-party SDKs: Apple signed notifications and the App Store
  Server API, Google Play products and subscriptionsv2 with authenticated Pub/Sub pushes, and
  Stripe webhook signatures.
- Eloquent models for purchases, items, subscriptions, refunds and a raw notification audit log —
  all swappable, with prices as exact money-for-laravel `Money`.
- Refunds and chargebacks as a first-class `PurchaseRefund`, linked to the original purchase.
- Idempotent lifecycle events — `PurchaseRecorded`, `PurchaseCompleted`, `PurchaseFailed`,
  `PurchaseRefunded`, `ChargebackReceived` and `Subscription*` — that fire only when something
  changed.
- Subscription scopes and helpers (`active()`, `expiring()`, `onTrial()`,
  `daysUntilRenewal()`) and the `HasPurchases` owner trait (`subscribedTo()`,
  `activeSubscription()`, delegating to `Purchases::for()`).
- Optional webhook routes and queued webhook processing.
- The `purchases:install`, `purchases:providers`, `purchases:verify` and `purchases:replay`
  commands.
- `Purchases::fake()` — a `PurchasesManager` subtype, so injected managers get it too — recording
  `handle()`, `sync()` and `replay()` with `assertHandled()`, `assertSynced()`,
  `assertReplayed()` (each with an `assertNothing…()` twin), `assertHandledCount()` and
  `assertPurchaseRecorded()` / `assertSubscriptionStarted()` / `assertRefundRecorded()` over all
  three, plus `FakeResult` and `PayloadFactory` for tests.
