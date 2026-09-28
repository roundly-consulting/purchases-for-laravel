<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Testing\PurchasesFake;

/**
 * @method static \RoundlyConsulting\Purchases\Providers\Provider provider(string $id)
 * @method static bool has(string $id)
 * @method static \Illuminate\Support\Collection<string, \RoundlyConsulting\Purchases\Providers\Provider> providers()
 * @method static list<string> ids()
 * @method static \RoundlyConsulting\Purchases\Contracts\ProviderResult result(string $id, \Illuminate\Http\Request $request)
 * @method static \Illuminate\Database\Eloquent\Model handle(string $id, \Illuminate\Http\Request $request)
 * @method static \Illuminate\Database\Eloquent\Model|null sync(\RoundlyConsulting\Purchases\Contracts\ProviderResult $result)
 * @method static \Illuminate\Database\Eloquent\Model|null replay(\RoundlyConsulting\Purchases\Models\PurchaseNotification|int $notification)
 * @method static \RoundlyConsulting\Purchases\OwnerPurchases for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static PurchasesFake push(string $provider, \RoundlyConsulting\Purchases\Contracts\ProviderResult $result)
 * @method static void assertHandled(string $provider)
 * @method static void assertHandledCount(int $count)
 * @method static void assertNothingHandled()
 * @method static void assertSynced(?string $provider = null)
 * @method static void assertNothingSynced()
 * @method static void assertReplayed(\RoundlyConsulting\Purchases\Models\PurchaseNotification|int|null $notification = null)
 * @method static void assertNothingReplayed()
 * @method static void assertPurchaseRecorded(?string $provider = null)
 * @method static void assertSubscriptionStarted(?string $provider = null)
 * @method static void assertRefundRecorded(?string $provider = null)
 *
 * @see PurchasesManager
 */
final class Purchases extends Facade
{
    /**
     * Swap the manager (facade and container) for a recording spy. Pushed results skip
     * signature verification; everything still runs the real recording pipeline, so rows
     * are written and lifecycle events fire.
     */
    public static function fake(): PurchasesFake
    {
        $fake = app(PurchasesFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PurchasesManager::class;
    }
}
