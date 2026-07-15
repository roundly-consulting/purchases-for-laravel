# Changelog

All notable changes to `purchases-for-laravel` will be documented in this file.

## Unreleased

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
