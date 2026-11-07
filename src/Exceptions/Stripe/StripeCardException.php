<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * The card was declined (`card_error`, HTTP 402). `declineCode` holds the issuer's reason.
 */
final class StripeCardException extends StripeException {}
