<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchase;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseItem;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseNotification;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseRefund;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscription;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscriptionItem;
use RoundlyConsulting\Purchases\Tests\TestCase;

/**
 * The base case for the model-swap proofs: every `purchases.models.*` key points at the
 * host subclass BEFORE the providers boot — which is what a real host does, by writing it
 * into `config/purchases.php`.
 *
 * The package's existing swap coverage (tests/Unit/Support/ConfiguredModelsTest) sets these
 * keys in the test BODY and then asserts on the RESOLVER. Both halves are weaker than they
 * look:
 *
 *  - a body-time swap happens after the providers booted and after the migrations ran, so
 *    it is structurally incapable of catching a boot-time or migration-time bug — a real
 *    host sets it before either. `reviews` shipped a passing swap test that could not
 *    catch the bug it was named for, exactly this way; and
 *  - asserting `PurchaseModel::class() === CustomPurchase::class` proves the resolver
 *    reads config. It says nothing about whether the package's own flows go THROUGH the
 *    resolver — which is the actual bug class (permissions #31/#34, shops #3, media #28).
 *
 * Pest binds a test case per DIRECTORY, not per file, so these live in tests/ModelSwap.
 */
abstract class SwappedModelsTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'purchases.models.purchase' => CustomPurchase::class,
            'purchases.models.purchase-item' => CustomPurchaseItem::class,
            'purchases.models.purchase-refund' => CustomPurchaseRefund::class,
            'purchases.models.purchase-notification' => CustomPurchaseNotification::class,
            'purchases.models.subscription' => CustomSubscription::class,
            'purchases.models.subscription-item' => CustomSubscriptionItem::class,
        ]);
    }
}
