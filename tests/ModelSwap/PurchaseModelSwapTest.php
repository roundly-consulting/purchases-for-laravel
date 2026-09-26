<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordPurchaseAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchase;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseItem;

/**
 * S — the model-swap proof, with every `purchases.models.*` key swapped BEFORE boot.
 *
 * This drives the package's real recording flow rather than asking the resolver what it
 * would return. `toHonourModelSwap` fails fast if the config does not already name the
 * subclass (so a forgotten before-boot swap is caught rather than silently proving
 * nothing), asserts every returned model's **concrete class**, and asserts a `created`
 * event landed on the subclass itself — the only proof the row was really created AS the
 * host's class rather than merely cast to it.
 */
it('records a purchase as the configured model', function (): void {
    expect('purchases.models.purchase')->toHonourModelSwap(CustomPurchase::class, function (): array {
        $purchase = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
            provider: 'stripe',
            providerId: 'pi_swap_1',
            status: Status::Completed,
            price: Money::ofMinor(1999, 'USD'),
            meta: ['id' => 'pi_swap_1'],
        ));

        // The write, and the read-back through the seam a host would use.
        return [$purchase, PurchaseModel::query()->where('provider_id', 'pi_swap_1')->first()];
    });
});

/**
 * The relation is where a derived key hides. Eloquent derives a `hasMany` foreign key from
 * the PARENT'S CLASS NAME, so a host subclass named `CustomPurchase` would have Eloquent
 * looking for `custom_purchase_id` unless the relation names its key explicitly. That is
 * the retrofit's single biggest bug class (12+ entries), and it is invisible until someone
 * actually swaps the model and traverses the relation.
 */
it('relates purchase items to the configured parent without deriving the key', function (): void {
    $purchase = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'pi_swap_2',
        status: Status::Completed,
        price: Money::ofMinor(500, 'USD'),
        meta: ['id' => 'pi_swap_2'],
    ));

    expect('purchases.models.purchase-item')->toHonourModelSwap(CustomPurchaseItem::class, function () use ($purchase): array {
        $item = $purchase->items()->create([
            'name' => 'Seat',
            'price' => Money::ofMinor(500, 'USD'),
        ]);

        // Created through the relation, and read back through it: if the FK were derived
        // from the subclass name, this read returns nothing and the concrete-class
        // assertion never even gets a model.
        return [$item, $purchase->fresh()?->items()->first()];
    });
});

/**
 * The read side of the seam: a lookup by provider id creates nothing, so the
 * created-event half is waived explicitly rather than skipped silently. A row hydrated as
 * the packaged base class would still satisfy `instanceof` while never firing the host's
 * events.
 */
it('reads an existing purchase back as the configured model', function (): void {
    (new RecordPurchaseAction)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'pi_swap_3',
        status: Status::Completed,
        price: Money::ofMinor(100, 'USD'),
        meta: ['id' => 'pi_swap_3'],
    ));

    expect('purchases.models.purchase')->toHonourModelSwap(
        CustomPurchase::class,
        fn (): array => [PurchaseModel::query()->where('provider_id', 'pi_swap_3')->first()],
        expectsCreation: false,
    );
});

/**
 * The update path: recording the same provider id twice must UPDATE the existing row, and
 * do it on the configured class. A second row here is a double-charge in a host's ledger;
 * an update performed as the packaged class skips the host's model events.
 */
it('updates rather than duplicates on the configured model', function (): void {
    $first = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'pi_swap_4',
        status: Status::Pending,
        price: Money::ofMinor(700, 'USD'),
        meta: ['id' => 'pi_swap_4'],
    ));

    expect('purchases.models.purchase')->toHonourModelSwap(
        CustomPurchase::class,
        function (): array {
            $second = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
                provider: 'stripe',
                providerId: 'pi_swap_4',
                status: Status::Completed,
                price: Money::ofMinor(700, 'USD'),
                meta: ['id' => 'pi_swap_4'],
            ));

            return [$second];
        },
        // An update creates no row — and that is itself the assertion: exactly one row
        // exists for this provider id.
        expectsCreation: false,
    );

    expect(PurchaseModel::query()->where('provider_id', 'pi_swap_4')->count())->toBe(1)
        ->and(PurchaseModel::query()->where('provider_id', 'pi_swap_4')->first()?->getKey())
        ->toBe($first->getKey());
});
