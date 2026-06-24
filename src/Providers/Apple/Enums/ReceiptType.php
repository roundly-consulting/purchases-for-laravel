<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum ReceiptType: string
{
    case Production = 'Production';
    case ProductionVPP = 'ProductionVPP';
    case ProductionSandbox = 'ProductionSandbox';
    case ProductionVPPSandbox = 'ProductionVPPSandbox';
}
