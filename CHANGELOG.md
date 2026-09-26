# Changelog

All notable changes to `purchases-for-laravel` will be documented in this file.

## Unreleased

### Fixed

- **Security:** Google Play Real-time Developer Notifications were accepted without any proof
  they came from Google — anyone could POST a forged Pub/Sub push (e.g. "subscription
  recovered" for their own token). `Google::notification()` now authenticates every push first,
  fail-closed: the push subscription's OIDC token (RS256 against Google's cached JWKS; issuer,
  audience, service-account email, `email_verified`, expiry) via
  `purchases.settings.google.push.audience` + `service_account_email`, and/or a `?token=` URL
  secret (`push.token`). With nothing configured every push is rejected;
  `push.authenticate = false` opts out for messages authenticated upstream.
- Apple App Store Server notifications are parsed from Apple's real payload: the optional
  `subtype` key (not `subType`) and the int64 `appAppleId`, absent in the sandbox. Every real
  notification previously failed with a `TypeError`. `ServerNotificationDecodedPayload::$subType`
  and `AppMetadata::$appAppleId` are nullable.
- An Apple refund records what was refunded: a `REFUND_PRORATED` refund is its
  `revocationPercentage` share of the price (rounded once), and a Family Sharing `REVOKE` records
  no amount. `TransactionInfo` exposes `revocationType` and `revocationPercentage`.
- Notifications recorded under the wrong type or status:
  - Apple DID_FAIL_TO_RENEW without a grace period and GRACE_PERIOD_EXPIRED were **Failed** (and
    fired `SubscriptionExpired`) although Apple keeps retrying billing for 60 days — now
    **OnHold**. An OFFER_REDEEMED DOWNGRADE (effective at the next renewal) no longer switches the
    plan now.
  - A refunded or revoked Apple subscription period, and a revoked Google subscription, left the
    `Subscription` **Completed** (active) — it is now **Refunded**. An Apple refund's `refunded_at`
    is its `revocationDate`, not the period's expiry.
  - A partial refund (a Stripe charge not fully `refunded`, a Google quantity-based partial void)
    flipped the whole purchase to **Refunded** — it is recorded and leaves the purchase
    completed (`RecordRefundData::$status`).
  - Stripe subscription billing (a subscription/setup-mode Checkout, a subscription invoice, a
    PaymentIntent paying an invoice) was recorded as a one-off **Purchase**, firing
    `PurchaseCompleted` for every renewal — it is audited only. A payment-mode Checkout is keyed
    on its PaymentIntent, so it and `payment_intent.*` record one purchase.
  - Stripe disputes: an inquiry (`warning_*`) and `charge.dispute.updated` were recorded as
    chargebacks, and a dispute closed as **won** left the purchase **Refunded** — inquiries and
    updates are informational, a won dispute reinstates the purchase, and a dispute keys on its
    own id so it no longer overwrites a refund of the same payment.
  - Stripe `unpaid` was **Failed** (`SubscriptionExpired`) and `paused` **Processing** — both are
    **OnHold**. Billing periods are read from subscription items (API versions since
    2025-03-31), where they moved; `Invoice::$subscription` from `parent.subscription_details`.
  - A verified Google subscription was keyed on `latestOrderId`, which changes every renewal —
    one subscription became many rows, none matching its RTDNs. It is keyed on the purchase
    token; a notification without an order id or expiry no longer wipes the stored ones.
- Lifecycle events fired again for every repeated delivery (all three stores deliver at least
  once): a duplicate `PurchaseCompleted` fulfilled an order twice. Events now fire only for a
  new row, a status that moved, a renewal that extended `ends_at`, or a new refunded amount.
- Apple notification types added since the provider was written no longer crash the webhook:
  ONE_TIME_CHARGE (now a completed `Purchase` with its price), REFUND_REVERSED (reinstates the
  purchase or subscription), RENEWAL_EXTENSION, EXTERNAL_PURCHASE_TOKEN, METADATA_UPDATE,
  MIGRATION, PRICE_CHANGE, RESCIND_CONSENT, the subtypes FAILURE, PRODUCT_NOT_FOR_SALE, SUMMARY,
  CREATED, ACTIVE_TOKEN_REMINDER, UNREPORTED, and the notifications without `data` (their
  `summary` / `externalPurchaseToken` / `appData` is kept raw). A type Apple adds later parses as
  `NotificationType::Unknown`. A Family Sharing transaction carries no price.
- Informational notifications are never applied: an Apple renewal-preference or auto-renew
  change, price increase, consumption request, declined refund (previously marked the
  subscription **Failed** and fired `SubscriptionExpired`), TEST or unknown type; a Google test,
  one-time-product, deferral, price-change, pause-schedule or unknown RTDN; and a Stripe event the
  package does not map (previously recorded as a bogus `Purchase`). `SyncProviderResultAction`
  returns `null` for `Notification` / `Unknown` results and `handle()` returns the audit
  notification. `PayloadFactory::appleNotification()` emits a payload
  `ServerNotificationDecodedPayload::fromRaw()` parses (`?string $subType`).
- Stripe `checkout.session.completed`, `invoice.paid` and `invoice.payment_failed` results read
  their own amount (`amount_total`, `amount_paid`, `amount_due`) instead of a missing `amount`,
  and their status: a paid session or invoice is Completed, an unpaid session Pending, a failed
  invoice Failed.

### Changed — prices are money-for-laravel `Money`

- `price` on `Purchase`, `PurchaseItem`, `PurchaseRefund`, `Subscription` and `SubscriptionItem`
  is cast with `AsMoney`; the columns are `decimal(38,0)` `price` + `price_currency`
  (`$table->money('price', nullable: true)`, migrations edited in place). The cast refuses raw
  integers.
- The built-in `ValueObjects\Money`, `Concerns\HasPrice`, `InvalidMoneyException` and
  `CurrencyMismatchException` are removed. Amounts are strings: `->amount` → `->minor()`,
  `->currency` → `->currency()->code`, `Money::of()` → `Money::ofMinor()`.
- Notification snapshots store the price as `{minor, decimal, currency}` (`minor` a string);
  the old `{amount, currency}` shape is not read. A snapshot with a price money refuses is
  skipped by `purchases:replay`.
- Stripe amounts must be an int or integer string (a float is refused, not truncated) and are
  re-scaled for Stripe's ISO deviations (ISK, UGX, MGA).
- Apple results carry the transaction price (milli-units); Google subscriptionsv2 results carry
  the line items' recurring price. Requires `ext-bcmath`.

### Changed — Apple certificate validity is now enforced

An App Store Server notification is rejected when any certificate in its `x5c` chain (leaf,
intermediate, or root) is outside its `notBefore..notAfter` window. Previously only the chain
signatures and the pinned fingerprints were checked, so an **expired** certificate was accepted.

- **A replayed or archived notification signed by a since-rotated, now-expired certificate is
  now rejected** where it used to be accepted. This is intended: an expired chain is not
  trustworthy. If you replay historical Apple payloads, expect them to fail once their signing
  certificate has lapsed.
- The check carries a clock-skew tolerance applied to **both** ends of the window:
  `purchases.settings.apple.certificate_clock_skew` (env `PURCHASES_APPLE_CERTIFICATE_CLOCK_SKEW`),
  in **seconds**, default **60**, valid range **0–3600**. A value outside that range throws
  `Exceptions\InvalidConfigurationException` rather than falling back to a default, so the check
  cannot be silently disabled.
- The rejection raises `Exceptions\VerificationException` with its own message, naming the
  certificate and the instant it lapsed — an expired certificate is never indistinguishable from
  a bad signature.

Apple's pinned WWDR-G6 and Root-CA-G3 SHA-1 fingerprints, the three-certificate chain length, and
the chain-linkage requirement are unchanged.

### Changed — X.509 primitives moved to crypto-for-laravel

- `Providers\Apple\ValueObjects\CertificateChain` was **removed**. The `x5c` chain is now parsed,
  fingerprinted, and linkage-checked through `RoundlyConsulting\Crypto\X509\{Chain, Certificate}`.
  The trust ruling (pinned anchors, chain length, validity policy) stays in
  `Providers\Apple\Jws\JwsVerifier`.
- `ext-openssl` left `require`: the package no longer calls any `openssl_*` function directly.
