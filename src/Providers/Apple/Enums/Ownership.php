<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum Ownership: string
{
    case FamilyShared = 'FAMILY_SHARED';
    case Purchased = 'PURCHASED';
}
