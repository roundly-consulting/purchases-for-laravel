<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * Convenience builders for fake provider results, so tests can drive
 * PurchasesFake::push() without crafting raw Apple/Google/Stripe payloads.
 */
final class FakeResult
{
    public static function purchase(string $provider = 'stripe', ?string $providerId = null, Status $status = Status::Completed): GenericResult
    {
        return new GenericResult(
            provider: $provider,
            type: ResultType::Purchase,
            providerId: $providerId ?? ('pi_'.uniqid()),
            status: $status,
            transactionId: $providerId ?? ('pi_'.uniqid()),
            price: new Money(999, 'USD'),
        );
    }

    public static function subscription(string $provider = 'stripe', ?string $providerId = null, Status $status = Status::Completed, ?string $name = 'pro'): GenericResult
    {
        return new GenericResult(
            provider: $provider,
            type: ResultType::Subscription,
            providerId: $providerId ?? ('sub_'.uniqid()),
            status: $status,
            transactionId: $providerId ?? ('sub_'.uniqid()),
            name: $name,
            productId: $name,
            price: new Money(1999, 'USD'),
            activeFrom: Carbon::now(),
            endsAt: Carbon::now()->addMonth(),
        );
    }

    public static function refund(string $provider = 'stripe', ?string $providerId = null, bool $chargeback = false): GenericResult
    {
        return new GenericResult(
            provider: $provider,
            type: ResultType::Refund,
            providerId: $providerId ?? ('re_'.uniqid()),
            status: Status::Refunded,
            transactionId: $providerId ?? ('re_'.uniqid()),
            price: new Money(999, 'USD'),
            refundReason: $chargeback ? 'fraudulent' : 'requested_by_customer',
            chargeback: $chargeback,
        );
    }
}
