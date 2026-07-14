<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Hash\Hmac;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Stripe\WebhookSignature;

function signStripe(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= Carbon::now()->getTimestamp();
    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex("{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

afterEach(fn () => Carbon::setTestNow());

it('produces the exact signature stripe produces', function (): void {
    // Frozen vector, computed from the pre-retrofit hash_hmac() implementation.
    // If the scheme framing ("{t}.{payload}"), the algorithm, or the hex output
    // ever drifts, this fails — real Stripe webhooks would stop verifying.
    $payload = '{"id":"evt_frozen","type":"payment_intent.succeeded"}';
    $secret = 'whsec_frozen_test_secret';
    $timestamp = 1700000000;

    $header = "t={$timestamp},v1=3c7a321d83e2a9e7e532e5d8e463f998a69ea16c8559157cf84735516f37b619";

    expect(signStripe($payload, $secret, $timestamp))->toBe($header);

    Carbon::setTestNow(Carbon::createFromTimestamp($timestamp));

    (new WebhookSignature)->verify($payload, $header, $secret);
});

it('accepts a valid signature', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test');

    (new WebhookSignature)->verify($payload, $header, 'whsec_test');
})->throwsNoExceptions();

it('rejects a tampered payload', function (): void {
    $header = signStripe('{"id":"evt_1"}', 'whsec_test');

    (new WebhookSignature)->verify('{"id":"evt_2"}', $header, 'whsec_test');
})->throws(VerificationException::class, 'Stripe webhook signature mismatch.');

it('rejects a wrong secret', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test');

    (new WebhookSignature)->verify($payload, $header, 'whsec_other');
})->throws(VerificationException::class, 'Stripe webhook signature mismatch.');

it('rejects a signature bound to a different timestamp', function (): void {
    // A valid MAC replayed under a fresh `t=` must not verify: the timestamp is
    // inside the signed string, so it cannot be swapped to dodge the tolerance.
    $payload = '{"id":"evt_1"}';
    $now = Carbon::now()->getTimestamp();
    $old = $now - 1000;

    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex("{$old}.{$payload}", 'whsec_test');

    (new WebhookSignature)->verify($payload, "t={$now},v1={$signature}", 'whsec_test');
})->throws(VerificationException::class, 'Stripe webhook signature mismatch.');

it('rejects a replayed event outside the tolerance window', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() - 301);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 300);
})->throws(VerificationException::class, 'Stripe webhook timestamp is outside the tolerance zone.');

it('accepts an event on the edge of the tolerance window', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() - 300);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 300);
})->throwsNoExceptions();

it('rejects a future timestamp outside the tolerance window', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() + 301);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 300);
})->throws(VerificationException::class, 'Stripe webhook timestamp is outside the tolerance zone.');

it('rejects a malformed header', function (): void {
    (new WebhookSignature)->verify('{}', 'not-a-signature-header', 'whsec_test');
})->throws(VerificationException::class, 'Malformed Stripe-Signature header.');

it('rejects a header with no v1 signature', function (): void {
    (new WebhookSignature)->verify('{}', 't=1700000000', 'whsec_test');
})->throws(VerificationException::class, 'Malformed Stripe-Signature header.');

it('rejects a non-numeric timestamp', function (): void {
    (new WebhookSignature)->verify('{}', 't=soon,v1=deadbeef', 'whsec_test');
})->throws(VerificationException::class, 'Malformed Stripe-Signature header.');

it('accepts a header with multiple v1 signatures', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = Carbon::now()->getTimestamp();
    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex("{$timestamp}.{$payload}", 'whsec_test');
    $header = "t={$timestamp},v1=deadbeef,v1={$signature}";

    (new WebhookSignature)->verify($payload, $header, 'whsec_test');
})->throwsNoExceptions();

it('rejects a truncated prefix of a valid signature', function (): void {
    // A constant-time compare is length-aware: a prefix must never pass.
    $payload = '{"id":"evt_1"}';
    $timestamp = Carbon::now()->getTimestamp();
    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex("{$timestamp}.{$payload}", 'whsec_test');

    (new WebhookSignature)->verify($payload, "t={$timestamp},v1=".substr($signature, 0, 32), 'whsec_test');
})->throws(VerificationException::class, 'Stripe webhook signature mismatch.');

it('skips the tolerance check when disabled', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() - 100000);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 0);
})->throwsNoExceptions();
