<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * Too many requests (HTTP 429). Retry with exponential backoff and the same idempotency key.
 */
final class StripeRateLimitException extends StripeException {}
