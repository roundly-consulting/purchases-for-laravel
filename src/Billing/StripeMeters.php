<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripeMeterAction;
use RoundlyConsulting\Purchases\Actions\Stripe\FindStripeMeterAction;
use RoundlyConsulting\Purchases\Actions\Stripe\ReportStripeMeterEventAction;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterEventData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Jobs\ReportStripeMeterEvent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Meter;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\MeterEvent;

/**
 * Usage-based billing through Stripe Billing Meters — `Purchases::stripe()->meters()`.
 *
 * A customer is an owner model with a Stripe customer, or a Stripe customer id (`cus_…`).
 */
final readonly class StripeMeters
{
    /**
     * @param  (Closure(string, list<mixed>): void)|null  $recorder
     */
    public function __construct(
        private Container $container,
        private ?Closure $recorder = null,
    ) {}

    public function create(MeterData $data, ?string $idempotencyKey = null): Meter
    {
        $this->record('meters.create', [$data, $idempotencyKey]);

        return $this->container->make(CreateStripeMeterAction::class)->execute($data, $idempotencyKey);
    }

    /**
     * The active meter for an event name, or null.
     */
    public function find(string $eventName): ?Meter
    {
        return $this->container->make(FindStripeMeterAction::class)->execute($eventName);
    }

    /**
     * The active meter for the event name, created as described when there is none.
     */
    public function findOrCreate(MeterData $data, ?string $idempotencyKey = null): Meter
    {
        return $this->find($data->eventName) ?? $this->create($data, $idempotencyKey);
    }

    /**
     * Report usage now. The identifier makes it idempotent (one is generated when none is given).
     */
    public function report(Model|string $customer, string $eventName, int|float|string $value, ?CarbonInterface $timestamp = null, ?string $identifier = null): MeterEvent
    {
        $event = $this->event($customer, $eventName, $value, $timestamp, $identifier);

        $this->record('meters.report', [$event]);

        return $this->container->make(ReportStripeMeterEventAction::class)->execute($event);
    }

    /**
     * Report usage on the queue (`purchases.queue.connection` / `.queue`). Returns the event,
     * whose identifier is fixed now, so a retried job never counts twice.
     */
    public function queue(Model|string $customer, string $eventName, int|float|string $value, ?CarbonInterface $timestamp = null, ?string $identifier = null): MeterEventData
    {
        // Stamped now: a queued report must bill when the usage happened, not when it ran.
        $event = $this->event($customer, $eventName, $value, $timestamp ?? Carbon::now(), $identifier);

        $this->record('meters.queue', [$event]);

        ReportStripeMeterEvent::dispatch($event);

        return $event;
    }

    private function event(Model|string $customer, string $eventName, int|float|string $value, ?CarbonInterface $timestamp, ?string $identifier): MeterEventData
    {
        if ($customer instanceof Model) {
            $customer = $this->container->make(BillingCustomers::class)->customerId($customer, 'stripe')
                ?? throw BillingException::noCustomer($customer);
        }

        return new MeterEventData($eventName, $customer, $value, $timestamp, $identifier);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function record(string $operation, array $arguments): void
    {
        if ($this->recorder !== null) {
            ($this->recorder)($operation, $arguments);
        }
    }
}
