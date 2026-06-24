<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Stripe\WebhookSignature;

function signStripe(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= Carbon::now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

afterEach(fn () => Carbon::setTestNow());

it('accepts a valid signature', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test');

    (new WebhookSignature)->verify($payload, $header, 'whsec_test');
})->throwsNoExceptions();

it('rejects a tampered payload', function (): void {
    $header = signStripe('{"id":"evt_1"}', 'whsec_test');

    (new WebhookSignature)->verify('{"id":"evt_2"}', $header, 'whsec_test');
})->throws(VerificationException::class);

it('rejects a wrong secret', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test');

    (new WebhookSignature)->verify($payload, $header, 'whsec_other');
})->throws(VerificationException::class);

it('rejects an expired timestamp', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() - 1000);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 300);
})->throws(VerificationException::class);

it('rejects a malformed header', function (): void {
    (new WebhookSignature)->verify('{}', 'not-a-signature-header', 'whsec_test');
})->throws(VerificationException::class);

it('accepts a header with multiple v1 signatures', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = Carbon::now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');
    $header = "t={$timestamp},v1=deadbeef,v1={$signature}";

    (new WebhookSignature)->verify($payload, $header, 'whsec_test');
})->throwsNoExceptions();

it('skips the tolerance check when disabled', function (): void {
    $payload = '{"id":"evt_1"}';
    $header = signStripe($payload, 'whsec_test', Carbon::now()->getTimestamp() - 100000);

    (new WebhookSignature)->verify($payload, $header, 'whsec_test', 0);
})->throwsNoExceptions();
