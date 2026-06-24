<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use SensitiveParameter;

/**
 * Native Stripe webhook signature verification — implements Stripe's documented
 * HMAC-SHA256 scheme over "{timestamp}.{payload}" with constant-time comparison
 * and a replay tolerance, with no stripe/stripe-php dependency.
 *
 * @link https://docs.stripe.com/webhooks#verify-manually
 */
final class WebhookSignature
{
    /**
     * Verify a raw request body against the Stripe-Signature header.
     *
     * @throws VerificationException when the signature is missing, malformed,
     *                               does not match, or is outside the tolerance.
     */
    public function verify(
        string $payload,
        string $header,
        #[SensitiveParameter] string $secret,
        int $tolerance = 300,
    ): void {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            throw VerificationException::because('Malformed Stripe-Signature header.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        $matched = false;

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw VerificationException::because('Stripe webhook signature mismatch.');
        }

        if ($tolerance > 0 && abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            throw VerificationException::because('Stripe webhook timestamp is outside the tolerance zone.');
        }
    }
}
