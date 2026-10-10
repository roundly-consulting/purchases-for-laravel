<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Results\GenericResult;

/**
 * Convenience builders for fake provider results, so tests can drive
 * PurchasesFake::push() without crafting raw Apple/Google/Stripe payloads.
 *
 * Like a real result, each one carries one id as both its provider and transaction id, and
 * the time it happened (`occurredAt`, now unless given), so event ordering applies to it.
 */
final class FakeResult
{
    public static function purchase(string $provider = 'stripe', ?string $providerId = null, Status $status = Status::Completed, ?CarbonInterface $occurredAt = null): GenericResult
    {
        $id = $providerId ?? 'pi_'.uniqid();

        return new GenericResult(
            provider: $provider,
            type: ResultType::Purchase,
            providerId: $id,
            status: $status,
            transactionId: $id,
            price: Money::ofMinor(999, 'USD'),
            occurredAt: $occurredAt ?? Carbon::now(),
        );
    }

    public static function subscription(string $provider = 'stripe', ?string $providerId = null, Status $status = Status::Completed, ?string $name = 'pro', ?CarbonInterface $occurredAt = null): GenericResult
    {
        $id = $providerId ?? 'sub_'.uniqid();

        return new GenericResult(
            provider: $provider,
            type: ResultType::Subscription,
            providerId: $id,
            status: $status,
            transactionId: $id,
            name: $name,
            productId: $name,
            price: Money::ofMinor(1999, 'USD'),
            activeFrom: Carbon::now(),
            endsAt: Carbon::now()->addMonth(),
            occurredAt: $occurredAt ?? Carbon::now(),
        );
    }

    public static function refund(string $provider = 'stripe', ?string $providerId = null, bool $chargeback = false, ?CarbonInterface $occurredAt = null): GenericResult
    {
        $id = $providerId ?? 're_'.uniqid();

        return new GenericResult(
            provider: $provider,
            type: ResultType::Refund,
            providerId: $id,
            status: Status::Refunded,
            transactionId: $id,
            price: Money::ofMinor(999, 'USD'),
            refundReason: $chargeback ? 'fraudulent' : 'requested_by_customer',
            chargeback: $chargeback,
            occurredAt: $occurredAt ?? Carbon::now(),
        );
    }
}
