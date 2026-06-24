<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;

/**
 * Re-runs stored audit notifications through the recording pipeline. Useful for
 * recovering from a downstream failure without re-receiving provider webhooks.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'purchases:replay {id? : A single notification id to replay}
        {--provider= : Only replay notifications from this provider}
        {--since= : Only replay notifications recorded on/after this date}';

    protected $description = 'Re-run stored provider notifications through the recording pipeline.';

    public function handle(SyncProviderResultAction $sync): int
    {
        /** @var class-string<PurchaseNotification> $model */
        $model = config('purchases.models.purchase-notification', PurchaseNotification::class);

        $query = $model::query();

        $this->applyFilters($query);

        $notifications = $query->orderBy('id')->get();

        if ($notifications->isEmpty()) {
            $this->warn('No notifications matched the given filters.');

            return self::SUCCESS;
        }

        $replayed = 0;

        foreach ($notifications as $notification) {
            $result = $this->resultFor($notification);

            if ($result === null) {
                $this->warn("Skipped notification #{$notification->getKey()}: could not rebuild result.");

                continue;
            }

            $sync->execute($result);
            $notification->update(['processed_at' => Carbon::now()]);
            $replayed++;
        }

        $this->info("Replayed {$replayed} notification(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<PurchaseNotification>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $id = $this->argument('id');

        if ($id !== null) {
            $query->whereKey($id);
        }

        $provider = $this->option('provider');

        if (is_string($provider) && $provider !== '') {
            $query->where('provider', $provider);
        }

        $since = $this->option('since');

        if (is_string($since) && $since !== '') {
            $query->where('created_at', '>=', Carbon::parse($since));
        }
    }

    private function resultFor(PurchaseNotification $notification): ?GenericResult
    {
        return NotificationResultFactory::fromNotification($notification);
    }
}
