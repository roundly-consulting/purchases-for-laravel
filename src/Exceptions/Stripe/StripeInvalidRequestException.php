<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

/**
 * Stripe refused the parameters, or an id it does not know (`invalid_request_error`, HTTP 400 / 404). `param` names the parameter.
 */
final class StripeInvalidRequestException extends StripeException {}
