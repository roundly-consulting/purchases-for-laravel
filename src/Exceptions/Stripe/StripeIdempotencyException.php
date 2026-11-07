<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * The idempotency key was already used with other parameters or another endpoint (`idempotency_error`).
 */
final class StripeIdempotencyException extends StripeException {}
