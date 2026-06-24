<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Purchases\Purchases as PurchasesManager;

/**
 * @method static \RoundlyConsulting\Purchases\Providers\Provider provider(string $id)
 * @method static bool has(string $id)
 * @method static \Illuminate\Support\Collection<string, \RoundlyConsulting\Purchases\Providers\Provider> providers()
 * @method static list<string> ids()
 * @method static \RoundlyConsulting\Purchases\Contracts\ProviderResult result(string $id, \Illuminate\Http\Request $request)
 * @method static \Illuminate\Database\Eloquent\Model handle(string $id, \Illuminate\Http\Request $request)
 *
 * @see PurchasesManager
 */
final class Purchases extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PurchasesManager::class;
    }
}
