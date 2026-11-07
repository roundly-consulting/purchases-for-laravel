<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * Stripe refused the secret key, or the key lacks a permission the call needs (HTTP 401 / 403).
 */
final class StripeAuthenticationException extends StripeException {}
