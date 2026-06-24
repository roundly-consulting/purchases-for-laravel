<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Purchases\Exceptions\UnknownProviderException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Purchases;

/**
 * Opt-in webhook endpoint. Resolves the provider from the URL, verifies and
 * persists the result, and returns 204. Disabled unless routes are enabled.
 */
final class WebhookController
{
    public function __construct(
        private readonly Purchases $purchases,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        if (! $this->purchases->has($provider)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $this->purchases->handle($provider, $request);
        } catch (VerificationException|UnknownProviderException) {
            abort(Response::HTTP_BAD_REQUEST);
        }

        return response()->noContent();
    }
}
