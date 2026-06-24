<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Contracts;

use RoundlyConsulting\Purchases\DataTransferObjects\ConnectivityResult;

/**
 * A provider that can prove its configured credentials genuinely work by making a
 * cheap authenticated call (token exchange or a lightweight authed request).
 */
interface VerifiesConnectivity
{
    public function verifyConnectivity(): ConnectivityResult;
}
