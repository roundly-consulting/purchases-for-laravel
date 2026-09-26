<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Testing\FakeResult;

it('round-trips a result through a snapshot', function (): void {
    $original = FakeResult::subscription('stripe', 'sub_rt');

    $notification = PurchaseNotification::factory()->create([
        'payload' => NotificationResultFactory::snapshot($original),
    ]);

    $rebuilt = NotificationResultFactory::fromNotification($notification);

    expect($rebuilt)->not->toBeNull()
        ->and($rebuilt?->provider())->toBe('stripe')
        ->and($rebuilt?->type())->toBe(ResultType::Subscription)
        ->and($rebuilt?->providerId())->toBe('sub_rt')
        ->and($rebuilt?->status())->toBe(Status::Completed)
        ->and($rebuilt?->price()?->minor())->toBe('1999')
        ->and($rebuilt?->endsAt())->toBeInstanceOf(Carbon::class);
});

it('round-trips a refund snapshot', function (): void {
    $original = FakeResult::refund('stripe', 're_rt', chargeback: true);

    $notification = PurchaseNotification::factory()->create([
        'payload' => NotificationResultFactory::snapshot($original),
    ]);

    $rebuilt = NotificationResultFactory::fromNotification($notification);

    expect($rebuilt?->type())->toBe(ResultType::Refund)
        ->and($rebuilt?->isChargeback())->toBeTrue()
        ->and($rebuilt?->refundReason())->toBe('fraudulent');
});

it('returns null when the snapshot is incomplete', function (): void {
    $notification = PurchaseNotification::factory()->create([
        'payload' => ['raw' => ['foo' => 'bar']],
    ]);

    expect(NotificationResultFactory::fromNotification($notification))->toBeNull();
});

it('snapshots the price in the money array shape with a string minor', function (): void {
    $snapshot = NotificationResultFactory::snapshot(FakeResult::purchase('stripe', 'pi_shape'));

    expect($snapshot['price'])->toBe(['minor' => '999', 'decimal' => '9.99', 'currency' => 'USD']);

    $notification = PurchaseNotification::factory()->create(['payload' => $snapshot]);
    $stored = json_decode((string) DB::table($notification->getTable())->where('id', $notification->getKey())->value('payload'), true);

    expect($stored['price']['minor'])->toBe('999');
});

it('snapshots a missing price as null', function (): void {
    $result = new GenericResult(provider: 'stripe', type: ResultType::Purchase, providerId: 'pi_free', status: Status::Completed);

    expect(NotificationResultFactory::snapshot($result)['price'])->toBeNull();
});

it('reads the price back from a snapshot', function (mixed $price, ?string $minor): void {
    $payload = NotificationResultFactory::snapshot(FakeResult::purchase('stripe', 'pi_read'));
    $payload['price'] = $price;

    $rebuilt = NotificationResultFactory::fromNotification(PurchaseNotification::factory()->create(['payload' => $payload]));

    expect($rebuilt)->not->toBeNull()
        ->and($rebuilt?->price()?->minor())->toBe($minor);
})->with([
    'string minor' => [['minor' => '1999', 'decimal' => '19.99', 'currency' => 'USD'], '1999'],
    'int minor' => [['minor' => 1999, 'currency' => 'USD'], '1999'],
    'decimal only' => [['decimal' => '19.99', 'currency' => 'USD'], '1999'],
    'beyond 64 bits' => [['minor' => '123456789012345678901234567890', 'currency' => 'USD'], '123456789012345678901234567890'],
    'no price' => [null, null],
    'not an array' => ['1999 USD', null],
]);

it('treats a price money refuses as an unparseable snapshot', function (array $price): void {
    $payload = NotificationResultFactory::snapshot(FakeResult::purchase('stripe', 'pi_bad'));
    $payload['price'] = $price;

    expect(NotificationResultFactory::fromNotification(PurchaseNotification::factory()->create(['payload' => $payload])))->toBeNull();
})->with([
    'float minor' => [['minor' => 19.99, 'currency' => 'USD']],
    'minor and decimal disagree' => [['minor' => '1999', 'decimal' => '20.00', 'currency' => 'USD']],
    'unknown currency' => [['minor' => '1999', 'currency' => 'ZZZ']],
    'no currency' => [['minor' => '1999']],
    'legacy amount shape' => [['amount' => 1999, 'currency' => 'USD']],
]);
