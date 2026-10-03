# Changelog

All notable changes to `purchases-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
