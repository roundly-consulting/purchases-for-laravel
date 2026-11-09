<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionPrice;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;
use RoundlyConsulting\Purchases\Tests\Support\Racer;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_limit');
    config()->set('purchases.subscriptions.one_per_owner', true);
    Http::preventStrayRequests();
});

/**
 * A company with a Stripe customer, and Stripe answering every new subscription with `$id`.
 */
function limitTestCompany(string $id = 'sub_1'): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake([
        'api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription(['id' => $id])),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_1']),
    ]);

    return $company;
}

function limitTestPlan(): CreateSubscriptionData
{
    return new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats', 1)], name: 'team');
}

it('starts as many subscriptions as asked when the limit is off', function (): void {
    config()->set('purchases.subscriptions.one_per_owner', false);
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::sequence()
        ->push(stripeBillingSubscription(['id' => 'sub_1']))
        ->push(stripeBillingSubscription(['id' => 'sub_2']))]);

    Purchases::stripe()->subscriptions()->create($company, limitTestPlan());
    Purchases::stripe()->subscriptions()->create($company, limitTestPlan());

    expect(Purchases::for($company)->subscriptions()->count())->toBe(2);
});

it('refuses a second subscription for an owner who holds one, before Stripe is asked', function (): void {
    $company = limitTestCompany();
    Purchases::stripe()->subscriptions()->create($company, limitTestPlan());

    expect(fn () => Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))
        ->toThrow(BillingException::class, 'already has a subscription');

    Http::assertSentCount(1);
    expect(Purchases::for($company)->subscriptions()->count())->toBe(1);
});

it('counts every subscription that is not over, of any provider', function (Status $status, bool $blocks): void {
    $company = limitTestCompany();
    ownedBy($company, Subscription::factory()->create(['provider' => 'apple', 'provider_id' => 'orig-1', 'status' => $status]));

    $start = fn () => Purchases::stripe()->subscriptions()->create($company, limitTestPlan());

    if ($blocks) {
        expect($start)->toThrow(BillingException::class);
    } else {
        expect($start())->toBeInstanceOf(Subscription::class);
    }

    expect(Purchases::for($company)->hasOngoingSubscription())->toBeTrue();
})->with([
    'pending' => [Status::Pending, true],
    'active' => [Status::Completed, true],
    'in grace' => [Status::InGracePeriod, true],
    'on hold' => [Status::OnHold, true],
    'processing' => [Status::Processing, true],
    'canceled' => [Status::Canceled, false],
    'expired' => [Status::Failed, false],
    'refunded' => [Status::Refunded, false],
]);

it('never counts another owner\'s subscription, nor one the host deleted', function (): void {
    $company = limitTestCompany();
    ownedBy(Organization::query()->create(['name' => 'Other']), Subscription::factory()->create(['status' => Status::Completed]));
    ownedBy($company, Subscription::factory()->create(['status' => Status::Completed]))->delete();

    expect(Purchases::for($company)->hasOngoingSubscription())->toBeFalse()
        ->and(Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))->toBeInstanceOf(Subscription::class);
});

it('opens no checkout for an owner who holds a subscription', function (): void {
    $company = limitTestCompany();
    $checkout = fn () => Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData([SubscriptionPrice::licensed('price_seats', 1)], 'https://app.test/ok'));

    expect($checkout()->id)->toBe('cs_1');

    ownedBy($company, Subscription::factory()->create(['status' => Status::Completed]));

    expect($checkout)->toThrow(BillingException::class, 'already has a subscription');
    Http::assertSentCount(1);
});

it('waits for a start already running for the owner, then gives up', function (): void {
    config()->set('purchases.subscriptions.lock.wait', 0);
    $company = limitTestCompany();
    $lock = Cache::lock('purchases:subscription:'.$company->getMorphClass().':'.$company->getKey(), 60);
    $lock->get();

    try {
        expect(fn () => Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))
            ->toThrow(BillingException::class, 'Another subscription is being started');
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
    expect(Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))->toBeInstanceOf(Subscription::class);
});

it('takes the lock on the store the host names', function (): void {
    config()->set('purchases.subscriptions.lock.store', 'file');
    $company = limitTestCompany();
    $lock = Cache::store('file')->lock('purchases:subscription:'.$company->getMorphClass().':'.$company->getKey(), 60);
    config()->set('purchases.subscriptions.lock.wait', 0);
    $lock->get();

    try {
        expect(fn () => Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))
            ->toThrow(BillingException::class, 'Another subscription is being started');
    } finally {
        $lock->release();
    }
});

it('refuses a lock store without atomic locks', function (): void {
    config()->set('cache.stores.plain', ['driver' => 'plain']);
    Cache::extend('plain', fn () => Cache::repository(new class implements Store
    {
        public function get($key): mixed
        {
            return null;
        }

        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    }));
    config()->set('purchases.subscriptions.lock.store', 'plain');
    $company = limitTestCompany();

    expect(fn () => Purchases::stripe()->subscriptions()->create($company, limitTestPlan()))
        ->toThrow(InvalidConfigurationException::class, 'purchases.subscriptions.lock.store');
});

it('starts one subscription when two starts for one owner race on a real engine', function (): void {
    config()->set('cache.default', 'database');
    config()->set('purchases.subscriptions.lock.wait', 20);
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    // Each process gets its own subscription id from Stripe, and Stripe is slow enough for
    // the two to overlap.
    Http::fake(['api.stripe.com/v1/subscriptions' => function (HttpRequest $request): mixed {
        usleep(300_000);

        return Http::response(stripeBillingSubscription(['id' => 'sub_'.getmypid()]));
    }]);

    $start = static function () use ($company): string {
        try {
            Purchases::stripe()->subscriptions()->create($company, limitTestPlan());
        } catch (BillingException $e) {
            return 'refused: '.$e->getMessage();
        }

        return 'started';
    };

    $outcomes = Racer::race([$start, $start]);
    sort($outcomes);

    expect($outcomes[0])->toContain('refused: ')
        ->and($outcomes[0])->toContain('already has a subscription')
        ->and($outcomes[1])->toBe('started')
        ->and(Purchases::for($company)->subscriptions()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs pgsql or mysql and pcntl');
