<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;

it('scopes purchases by provider, provider id, and transaction', function (): void {
    Purchase::factory()->create(['provider' => 'stripe', 'provider_id' => 'pi_1', 'transaction_id' => 'tx_1']);
    Purchase::factory()->create(['provider' => 'apple', 'provider_id' => 'pi_2', 'transaction_id' => 'tx_2']);

    expect(Purchase::forProvider('stripe')->count())->toBe(1)
        ->and(Purchase::byProviderId('pi_1')->first()?->provider)->toBe('stripe')
        ->and(Purchase::byTransaction('tx_2')->first()?->provider)->toBe('apple');
});

it('scopes subscriptions by provider identifiers', function (): void {
    Subscription::factory()->create(['provider' => 'stripe', 'provider_id' => 'sub_1', 'transaction_id' => 'tx_a']);
    Subscription::factory()->create(['provider' => 'google', 'provider_id' => 'sub_2', 'transaction_id' => 'tx_b']);

    expect(Subscription::forProvider('google')->count())->toBe(1)
        ->and(Subscription::byProviderId('sub_1')->first()?->provider)->toBe('stripe')
        ->and(Subscription::byTransaction('tx_b')->first()?->provider)->toBe('google');
});
