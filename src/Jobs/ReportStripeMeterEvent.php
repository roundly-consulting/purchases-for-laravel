<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Purchases\Actions\Stripe\ReportStripeMeterEventAction;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterEventData;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * Reports a usage event to a Stripe meter on the queue — `Purchases::stripe()->meters()
 * ->queue()`. It runs on the package's queue connection and queue (`purchases.queue.*`).
 * The event's identifier was fixed when it was queued, so a retried job is never counted
 * twice.
 */
final class ReportStripeMeterEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly MeterEventData $event,
    ) {
        $this->onConnection(PurchasesConfig::optionalString(config('purchases.queue.connection'), 'purchases.queue.connection'));
        $this->onQueue(PurchasesConfig::optionalString(config('purchases.queue.queue'), 'purchases.queue.queue'));
    }

    public function handle(ReportStripeMeterEventAction $report): void
    {
        $report->execute($this->event);
    }
}
