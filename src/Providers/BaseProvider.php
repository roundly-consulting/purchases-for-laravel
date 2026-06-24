<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

abstract class BaseProvider implements Provider
{
    public function id(): string
    {
        return Str::kebab(class_basename($this));
    }

    public function notification(Request $request): mixed
    {
        throw InvalidProviderNotificationException::because('No provider notification handling defined.');
    }

    public function callback(Request $request): mixed
    {
        throw VerificationException::because('No provider verification defined.');
    }
}
