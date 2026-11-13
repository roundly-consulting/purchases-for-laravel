<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

/**
 * Whether the configured subscriptions table has a column a later, additive migration adds
 * (`cancels_at`) — so a host that updated the package but has not published and run that
 * migration yet keeps recording subscriptions, just without the new column. Asked once per
 * connection and table, and remembered for the rest of the process: restart queue workers
 * after migrating.
 *
 * @internal building block of the recording actions.
 */
final class SubscriptionColumns
{
    /** @var array<string, bool> */
    private static array $known = [];

    public static function has(string $column): bool
    {
        $model = SubscriptionModel::new();
        $connection = $model->getConnection();
        $key = $connection->getName().'|'.$model->getTable().'|'.$column;

        return self::$known[$key] ??= $connection->getSchemaBuilder()->hasColumn($model->getTable(), $column);
    }

    /**
     * Forget every answer — after a migration ran in the same process.
     */
    public static function flush(): void
    {
        self::$known = [];
    }
}
