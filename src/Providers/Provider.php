<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers;

use Illuminate\Http\Request;

interface Provider
{
    public function id(): string;

    public function notification(Request $request): mixed;

    public function callback(Request $request): mixed;
}
