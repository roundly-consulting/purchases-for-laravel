<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordPurchaseAction;
use RoundlyConsulting\Purchases\Actions\RecordRefundAction;
use RoundlyConsulting\Purchases\Actions\RecordSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchase;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseItem;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseNotification;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseRefund;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscription;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscriptionItem;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

/**
 * `purchases.models` documents swapping any of the six models for a host subclass.
 * Eloquent derives a `hasMany` foreign key from the PARENT'S CLASS NAME, so a
 * relation declared without an explicit key silently looks for `custom_purchase_id`
 * the moment a host does exactly what the config invites — and every item read,
 * refund read and line-item insert breaks.
 *
 * These tests drive the whole lifecycle through host subclasses and pin each foreign
 * key by name. Dropping an explicit key turns them red.
 */
beforeEach(function (): void {
    config()->set('purchases.models.purchase', CustomPurchase::class);
    config()->set('purchases.models.purchase-item', CustomPurchaseItem::class);
    config()->set('purchases.models.purchase-refund', CustomPurchaseRefund::class);
    config()->set('purchases.models.purchase-notification', CustomPurchaseNotification::class);
    config()->set('purchases.models.subscription', CustomSubscription::class);
    config()->set('purchases.models.subscription-item', CustomSubscriptionItem::class);
});

it('keys every relation off the packaged foreign key, not the host class name', function (): void {
    $purchase = new CustomPurchase;
    $subscription = new CustomSubscription;

    expect($purchase->items()->getForeignKeyName())->toBe('purchase_id')
        ->and($purchase->refunds()->getForeignKeyName())->toBe('purchase_id')
        ->and($subscription->items()->getForeignKeyName())->toBe('subscription_id');

    // The derived keys these would fall back to, if the FK were ever un-named.
    expect($purchase->getForeignKey())->toBe('custom_purchase_id')
        ->and($subscription->getForeignKey())->toBe('custom_subscription_id');
});

it('records a purchase and its line items through the configured subclasses', function (): void {
    $purchase = app(RecordPurchaseAction::class)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'cs_swap_1',
        status: Status::Completed,
        price: Money::ofMinor(2500, 'EUR'),
        items: [
            new ResultItem(providerId: 'price_1', name: 'Pro plan', price: Money::ofMinor(2500, 'EUR'), quantity: 1),
        ],
    ));

    expect($purchase)->toBeInstanceOf(CustomPurchase::class)
        ->and($purchase->items)->toHaveCount(1)
        ->and($purchase->items->first())->toBeInstanceOf(CustomPurchaseItem::class)
        ->and($purchase->items->first()->purchase_id)->toBe($purchase->id);

    // The inverse resolves too — it keys off the relation name, so it only lines up
    // while the column stays `purchase_id`.
    expect($purchase->items->first()->purchase->is($purchase))->toBeTrue();
});

it('records a subscription and its items through the configured subclasses', function (): void {
    $subscription = app(RecordSubscriptionAction::class)->execute(new RecordSubscriptionData(
        provider: 'stripe',
        providerId: 'sub_swap_1',
        status: Status::Completed,
        name: 'pro',
        price: Money::ofMinor(999, 'EUR'),
        items: [
            new ResultItem(providerId: 'price_1', name: 'Pro seat', price: Money::ofMinor(999, 'EUR')),
        ],
    ));

    expect($subscription)->toBeInstanceOf(CustomSubscription::class)
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->items->first())->toBeInstanceOf(CustomSubscriptionItem::class)
        ->and($subscription->items->first()->subscription_id)->toBe($subscription->id)
        ->and($subscription->items->first()->subscription->is($subscription))->toBeTrue();
});

it('links a refund back to its purchase through the configured subclasses', function (): void {
    $purchase = app(RecordPurchaseAction::class)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'cs_swap_2',
        transactionId: 'pi_swap_2',
        status: Status::Completed,
        price: Money::ofMinor(2500, 'EUR'),
    ));

    $refund = app(RecordRefundAction::class)->execute(new RecordRefundData(
        provider: 'stripe',
        providerId: 're_swap_2',
        transactionId: 'pi_swap_2',
        price: Money::ofMinor(2500, 'EUR'),
    ));

    expect($refund)->toBeInstanceOf(CustomPurchaseRefund::class)
        ->and($refund->purchase_id)->toBe($purchase->id);

    // `refunds()` is the relation the derived key broke outright: `purchase_refunds`
    // has a hard-coded `purchase_id` column, so a derived `custom_purchase_id` is not
    // merely wrong, it does not exist.
    expect($purchase->fresh()->refunds)->toHaveCount(1)
        ->and($purchase->fresh()->refunds->first()->is($refund))->toBeTrue()
        ->and($purchase->fresh()->status)->toBe(Status::Refunded);
});

it('serves an owner its purchases and subscriptions through the configured subclasses', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $user->purchases()->create([
        'provider' => 'stripe',
        'provider_id' => 'cs_owner_1',
        'status' => Status::Completed,
    ]);

    $user->subscriptions()->create([
        'provider' => 'stripe',
        'provider_id' => 'sub_owner_1',
        'name' => 'pro',
        'status' => Status::Completed,
    ]);

    expect($user->purchases()->get())->toHaveCount(1)
        ->and($user->purchases()->first())->toBeInstanceOf(CustomPurchase::class)
        ->and($user->activeSubscription('pro'))->toBeInstanceOf(CustomSubscription::class)
        ->and($user->subscribedTo('pro'))->toBeTrue();
});
