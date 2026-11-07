<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A billing call the package refuses before Stripe is asked: an owner with no Stripe
 * customer, a subscription that is not Stripe's, a malformed id or idempotency key, a
 * negative quantity.
 */
final class BillingException extends Exception
{
    public static function noCustomer(Model $owner): self
    {
        return new self('The '.class_basename($owner).' has no Stripe customer yet. Sync one first with Purchases::stripe()->customers()->sync().');
    }

    public static function unsavedOwner(Model $owner): self
    {
        return new self('The '.class_basename($owner).' is not saved, so no Stripe customer can be linked to it.');
    }

    public static function customerTaken(string $customer): self
    {
        return new self("Stripe customer [{$customer}] already belongs to another owner.");
    }

    public static function notStripe(string $what): self
    {
        return new self("The {$what} is not a Stripe {$what}.");
    }

    public static function invalid(string $reason): self
    {
        return new self($reason);
    }
}
