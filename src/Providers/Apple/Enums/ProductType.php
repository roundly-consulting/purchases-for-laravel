<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum ProductType: string
{
    case AutoRenewableSubscription = 'Auto-Renewable Subscription';
    case NonRenewingSubscription = 'Non-Renewing Subscription';
    case NonConsumable = 'Non-Consumable';
    case Consumable = 'Consumable';
}
