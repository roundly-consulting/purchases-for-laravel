<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\EventType;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SubscriptionStatus;

it('falls back to unknown for unrecognized event names', function (): void {
    expect(EventType::fromName('payment_intent.succeeded'))->toBe(EventType::PaymentIntentSucceeded)
        ->and(EventType::fromName('something.weird'))->toBe(EventType::Unknown);
});

it('maps event types to result types', function (EventType $type, ResultType $expected): void {
    expect($type->resultType())->toBe($expected);
})->with([
    [EventType::SubscriptionCreated, ResultType::Subscription],
    [EventType::SubscriptionUpdated, ResultType::Subscription],
    [EventType::SubscriptionDeleted, ResultType::Subscription],
    [EventType::PaymentIntentSucceeded, ResultType::Purchase],
    [EventType::PaymentIntentFailed, ResultType::Purchase],
    [EventType::CheckoutSessionCompleted, ResultType::Purchase],
    [EventType::InvoicePaid, ResultType::Purchase],
    [EventType::InvoicePaymentFailed, ResultType::Purchase],
    [EventType::ChargeRefunded, ResultType::Refund],
    [EventType::ChargeDisputeCreated, ResultType::Refund],
    [EventType::ChargeDisputeClosed, ResultType::Refund],
    [EventType::ChargeDisputeUpdated, ResultType::Refund],
    [EventType::Unknown, ResultType::Unknown],
]);

it('flags disputes as chargebacks', function (): void {
    expect(EventType::ChargeDisputeCreated->isChargeback())->toBeTrue()
        ->and(EventType::ChargeRefunded->isChargeback())->toBeFalse();
});

it('maps payment intent status to status', function (PaymentIntentStatus $status, Status $expected): void {
    expect($status->status())->toBe($expected);
})->with([
    [PaymentIntentStatus::Succeeded, Status::Completed],
    [PaymentIntentStatus::Canceled, Status::Canceled],
    [PaymentIntentStatus::Processing, Status::Processing],
    [PaymentIntentStatus::RequiresCapture, Status::Processing],
    [PaymentIntentStatus::RequiresPaymentMethod, Status::Pending],
    [PaymentIntentStatus::RequiresConfirmation, Status::Pending],
    [PaymentIntentStatus::RequiresAction, Status::Pending],
]);

it('maps subscription status to status', function (SubscriptionStatus $status, Status $expected): void {
    expect($status->status())->toBe($expected);
})->with([
    [SubscriptionStatus::Trialing, Status::Completed],
    [SubscriptionStatus::Active, Status::Completed],
    [SubscriptionStatus::Incomplete, Status::Pending],
    [SubscriptionStatus::PastDue, Status::InGracePeriod],
    [SubscriptionStatus::Paused, Status::Processing],
    [SubscriptionStatus::Canceled, Status::Canceled],
    [SubscriptionStatus::IncompleteExpired, Status::Canceled],
    [SubscriptionStatus::Unpaid, Status::Failed],
]);
