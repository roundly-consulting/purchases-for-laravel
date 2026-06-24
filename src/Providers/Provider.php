<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers;

use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;

interface Provider
{
    public function id(): string;

    public function notification(Request $request): mixed;

    public function callback(Request $request): mixed;

    /**
     * Verify and decode the request into a provider-agnostic result.
     */
    public function result(Request $request): ProviderResult;
}
