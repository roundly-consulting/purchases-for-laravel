<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * Persists an already-verified provider result on a queue, so the webhook can
 * acknowledge immediately. Verification has already happened synchronously.
 */
final class ProcessProviderNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ProviderResult $result,
        public readonly ?int $notificationId = null,
    ) {
        /** @var string|null $connection */
        $connection = config('purchases.queue.connection');

        /** @var string|null $queue */
        $queue = config('purchases.queue.queue');

        $this->onConnection($connection);
        $this->onQueue($queue);
    }

    public function handle(SyncProviderResultAction $sync): void
    {
        $sync->execute($this->result);

        $this->markProcessed();
    }

    private function markProcessed(): void
    {
        if ($this->notificationId === null) {
            return;
        }

        PurchaseNotificationModel::query()
            ->whereKey($this->notificationId)
            ->update(['processed_at' => Carbon::now()]);
    }
}
