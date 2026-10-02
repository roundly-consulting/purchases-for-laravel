<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Providers\Google\Enums\AcknowledgementState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\NotificationType;
use RoundlyConsulting\Purchases\Providers\Google\Enums\PurchaseState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\SubscriptionState;

it('maps purchase state helpers', function (): void {
    expect(PurchaseState::Purchased->isPurchased())->toBeTrue()
        ->and(PurchaseState::Canceled->isPurchased())->toBeFalse();
});

it('maps acknowledgement state helpers', function (): void {
    expect(AcknowledgementState::Acknowledged->isAcknowledged())->toBeTrue()
        ->and(AcknowledgementState::YetToBeAcknowledged->isAcknowledged())->toBeFalse();
});

it('exposes notification types', function (): void {
    expect(NotificationType::tryFrom(2))->toBe(NotificationType::Renewed)
        ->and(NotificationType::tryFrom(13))->toBe(NotificationType::Expired)
        ->and(NotificationType::tryFrom(999))->toBeNull();
});

it('maps subscription state to status and helpers', function (SubscriptionState $state, Status $status, bool $active, bool $terminal): void {
    expect($state->status())->toBe($status)
        ->and($state->isActive())->toBe($active)
        ->and($state->isTerminal())->toBe($terminal);
})->with([
    [SubscriptionState::Active, Status::Completed, true, false],
    [SubscriptionState::InGracePeriod, Status::InGracePeriod, true, false],
    [SubscriptionState::Pending, Status::Pending, false, false],
    [SubscriptionState::Paused, Status::Processing, false, false],
    [SubscriptionState::OnHold, Status::OnHold, false, false],
    [SubscriptionState::Canceled, Status::Canceled, false, false],
    [SubscriptionState::Expired, Status::Failed, false, true],
    [SubscriptionState::Unspecified, Status::Processing, false, false],
]);

it('maps developer notification types to status', function (NotificationType $type, Status $status): void {
    expect($type->status())->toBe($status);
})->with([
    [NotificationType::Renewed, Status::Completed],
    [NotificationType::Purchased, Status::Completed],
    [NotificationType::InGracePeriod, Status::InGracePeriod],
    [NotificationType::OnHold, Status::OnHold],
    [NotificationType::Paused, Status::OnHold],
    [NotificationType::Canceled, Status::Completed],
    [NotificationType::Revoked, Status::Refunded],
    [NotificationType::Expired, Status::Failed],
    [NotificationType::Deferred, Status::Processing],
]);

it('flags revoked notifications as refunds', function (): void {
    expect(NotificationType::Revoked->isRefund())->toBeTrue()
        ->and(NotificationType::Renewed->isRefund())->toBeFalse();
});
