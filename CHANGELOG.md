# Changelog

All notable changes to `purchases-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- One API for Apple App Store, Google Play and Stripe purchases and subscriptions through the
  `Purchases` facade: `result()` verifies and decodes, `handle()` also persists and fires events.
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
  `activeSubscription()`).
- Optional webhook routes and queued webhook processing.
- The `purchases:install`, `purchases:providers`, `purchases:verify` and `purchases:replay`
  commands.
- `Purchases::fake()` with assertions, plus `FakeResult` and `PayloadFactory` for tests.
