<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * Stripe could not be reached (DNS, TLS, a timeout). Safe to retry with the same idempotency key.
 */
final class StripeConnectionException extends StripeException {}
