<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum Environment: string
{
    case Production = 'Production';
    case Sandbox = 'Sandbox';
}
