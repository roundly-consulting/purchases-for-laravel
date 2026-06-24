<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

it('resolves a configured provider by its kebab id', function (): void {
    config()->set('purchases.providers', [Google::class, Stripe::class]);

    $resolver = new Resolver;

    expect($resolver->resolve('google'))->toBeInstanceOf(Google::class)
        ->and($resolver->resolve('stripe'))->toBeInstanceOf(Stripe::class)
        ->and($resolver->resolve('missing'))->toBeNull();
});

it('lists the registered provider keys', function (): void {
    config()->set('purchases.providers', [Google::class, Stripe::class]);

    expect((new Resolver)->keys()->all())->toBe(['google', 'stripe']);
});

it('returns all registered providers keyed by id', function (): void {
    config()->set('purchases.providers', [Google::class]);

    expect((new Resolver)->all()->keys()->all())->toBe(['google']);
});

it('falls back to an empty provider list', function (): void {
    config()->set('purchases.providers', null);

    expect((new Resolver)->keys()->all())->toBe([]);
});

it('derives a kebab id from the provider class name', function (): void {
    expect((new Google)->id())->toBe('google')
        ->and((new Stripe)->id())->toBe('stripe');
});

it('throws from the default notification handler', function (): void {
    (new Google)->notification(new Request);
})->throws(InvalidProviderNotificationException::class);

it('throws from the default callback handler', function (): void {
    (new Stripe)->callback(new Request);
})->throws(VerificationException::class);
