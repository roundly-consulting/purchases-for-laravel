<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SubscriptionStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Invoice;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentIntent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeEvent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Subscription;

it('maps a payment intent with money', function (): void {
    $intent = PaymentIntent::fromRaw([
        'id' => 'pi_1',
        'status' => 'succeeded',
        'amount' => 1999,
        'currency' => 'usd',
        'customer' => 'cus_1',
        'description' => 'Pro',
    ]);

    expect($intent->id)->toBe('pi_1')
        ->and($intent->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->amount?->minor())->toBe('1999')
        ->and($intent->amount?->currency()->code)->toBe('USD')
        ->and($intent->customer)->toBe('cus_1');
});

it('returns null money for a non-numeric amount', function (): void {
    $intent = PaymentIntent::fromRaw(['id' => 'pi_1', 'status' => 'processing']);

    expect($intent->amount)->toBeNull();
});

it('maps a subscription with epoch dates', function (): void {
    $subscription = Subscription::fromRaw([
        'id' => 'sub_1',
        'status' => 'active',
        'current_period_start' => 1700000000,
        'current_period_end' => 1702592000,
        'trial_end' => null,
        'canceled_at' => 1701000000,
    ]);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->currentPeriodStart)->not->toBeNull()
        ->and($subscription->currentPeriodEnd)->not->toBeNull()
        ->and($subscription->trialEnd)->toBeNull()
        ->and($subscription->canceledAt)->not->toBeNull();
});

it('maps a checkout session and invoice', function (): void {
    $session = CheckoutSession::fromRaw([
        'id' => 'cs_1',
        'payment_status' => 'paid',
        'amount_total' => 2500,
        'currency' => 'gbp',
        'subscription' => 'sub_1',
    ]);

    $invoice = Invoice::fromRaw([
        'id' => 'in_1',
        'status' => 'paid',
        'amount_paid' => 4200,
        'currency' => 'usd',
    ]);

    expect($session->amountTotal?->minor())->toBe('2500')
        ->and($session->subscription)->toBe('sub_1')
        ->and($invoice->amountPaid?->minor())->toBe('4200');
});

it('decodes an event with a missing object', function (): void {
    $event = StripeEvent::fromRaw(['id' => 'evt_1', 'type' => 'invoice.paid']);

    expect($event->object)->toBe([]);
});
