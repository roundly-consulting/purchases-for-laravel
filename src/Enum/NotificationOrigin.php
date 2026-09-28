<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

use RoundlyConsulting\Enums\Helpers;

/**
 * Where an audited notification came from — stored as the `origin` key of its snapshot.
 *
 * Provider: a store notification the package verified itself (`Purchases::handle()`), so its
 * `signature_verified` is true. Host: a result the host app handed to `Purchases::sync()`; the
 * package verified nothing, so `signature_verified` is false and the host is vouching for it.
 * The key is written by the package, never read from provider data.
 */
enum NotificationOrigin: string
{
    use Helpers;

    case Provider = 'provider';
    case Host = 'host';
}
