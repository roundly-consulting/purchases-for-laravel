<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * Any other answer Stripe gave: a 5xx, a conflict (HTTP 409), a failure of a dependency (HTTP 424). Safe to retry with the same idempotency key.
 */
final class StripeApiException extends StripeException {}
