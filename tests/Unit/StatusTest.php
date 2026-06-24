<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Enum\Status;

it('exposes the expected purchase statuses', function (): void {
    expect(Status::New->value)->toBe('new')
        ->and(Status::Pending->value)->toBe('pending')
        ->and(Status::Processing->value)->toBe('processing')
        ->and(Status::Completed->value)->toBe('completed')
        ->and(Status::Failed->value)->toBe('failed')
        ->and(Status::Canceled->value)->toBe('canceled');
});

it('resolves a status from its backing value', function (): void {
    expect(Status::tryFrom('completed'))->toBe(Status::Completed)
        ->and(Status::tryFrom('unknown'))->toBeNull();
});

it('exposes the additive billing-retry statuses', function (): void {
    expect(Status::InGracePeriod->value)->toBe('in_grace')
        ->and(Status::OnHold->value)->toBe('on_hold')
        ->and(Status::Refunded->value)->toBe('refunded');
});

it('treats completed and grace period as active entitlements', function (Status $status, bool $active): void {
    expect($status->isActive())->toBe($active);
})->with([
    [Status::Completed, true],
    [Status::InGracePeriod, true],
    [Status::OnHold, false],
    [Status::Refunded, false],
    [Status::Canceled, false],
    [Status::Failed, false],
    [Status::Pending, false],
    [Status::New, false],
    [Status::Processing, false],
]);
