<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

use RoundlyConsulting\Purchases\Enum\ResultType;

/**
 * The subset of Stripe webhook event types this package understands. Unknown
 * events fall back to EventType::Unknown so callers never hit a hard failure.
 */
enum EventType: string
{
    case PaymentIntentSucceeded = 'payment_intent.succeeded';
    case PaymentIntentFailed = 'payment_intent.payment_failed';
    case CheckoutSessionCompleted = 'checkout.session.completed';
    case SubscriptionCreated = 'customer.subscription.created';
    case SubscriptionUpdated = 'customer.subscription.updated';
    case SubscriptionDeleted = 'customer.subscription.deleted';
    case InvoicePaid = 'invoice.paid';
    case InvoicePaymentFailed = 'invoice.payment_failed';
    case ChargeRefunded = 'charge.refunded';
    case ChargeDisputeCreated = 'charge.dispute.created';
    case ChargeDisputeClosed = 'charge.dispute.closed';
    case ChargeDisputeUpdated = 'charge.dispute.updated';
    case Unknown = 'unknown';

    public static function fromName(string $name): self
    {
        return self::tryFrom($name) ?? self::Unknown;
    }

    public function resultType(): ResultType
    {
        return match ($this) {
            self::SubscriptionCreated,
            self::SubscriptionUpdated,
            self::SubscriptionDeleted => ResultType::Subscription,
            self::ChargeRefunded,
            self::ChargeDisputeCreated,
            self::ChargeDisputeClosed,
            self::ChargeDisputeUpdated => ResultType::Refund,
            self::PaymentIntentSucceeded,
            self::PaymentIntentFailed,
            self::CheckoutSessionCompleted,
            self::InvoicePaid,
            self::InvoicePaymentFailed => ResultType::Purchase,
            self::Unknown => ResultType::Unknown,
        };
    }

    /**
     * Whether this event is a dispute / chargeback rather than a voluntary refund.
     */
    public function isChargeback(): bool
    {
        return match ($this) {
            self::ChargeDisputeCreated,
            self::ChargeDisputeClosed,
            self::ChargeDisputeUpdated => true,
            default => false,
        };
    }
}
