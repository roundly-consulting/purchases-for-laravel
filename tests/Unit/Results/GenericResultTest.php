<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\ValueObjects\Money;

it('exposes every provider-agnostic field', function (): void {
    $activeFrom = Carbon::parse('2026-01-01');
    $trialEndsAt = Carbon::parse('2026-01-08');
    $endsAt = Carbon::parse('2026-02-01');
    $item = new ResultItem(name: 'Pro', providerId: 'sku_pro', price: new Money(999, 'USD'), quantity: 2);

    $result = new GenericResult(
        provider: 'stripe',
        type: ResultType::Subscription,
        providerId: 'sub_123',
        status: Status::Completed,
        transactionId: 'txn_1',
        name: 'Pro plan',
        productId: 'prod_1',
        price: new Money(999, 'USD'),
        activeFrom: $activeFrom,
        trialEndsAt: $trialEndsAt,
        endsAt: $endsAt,
        items: [$item],
        raw: ['id' => 'sub_123'],
    );

    expect($result->provider())->toBe('stripe')
        ->and($result->type())->toBe(ResultType::Subscription)
        ->and($result->providerId())->toBe('sub_123')
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->transactionId())->toBe('txn_1')
        ->and($result->name())->toBe('Pro plan')
        ->and($result->productId())->toBe('prod_1')
        ->and($result->price()?->amount)->toBe(999)
        ->and($result->activeFrom())->toBe($activeFrom)
        ->and($result->trialEndsAt())->toBe($trialEndsAt)
        ->and($result->endsAt())->toBe($endsAt)
        ->and($result->items())->toBe([$item])
        ->and($result->raw())->toBe(['id' => 'sub_123']);
});

it('defaults optional fields to null and empty', function (): void {
    $result = new GenericResult(
        provider: 'google',
        type: ResultType::Purchase,
        providerId: 'token_1',
        status: Status::Pending,
    );

    expect($result->transactionId())->toBeNull()
        ->and($result->name())->toBeNull()
        ->and($result->productId())->toBeNull()
        ->and($result->price())->toBeNull()
        ->and($result->activeFrom())->toBeNull()
        ->and($result->trialEndsAt())->toBeNull()
        ->and($result->endsAt())->toBeNull()
        ->and($result->items())->toBe([])
        ->and($result->raw())->toBe([]);
});
