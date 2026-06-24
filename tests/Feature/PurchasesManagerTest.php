<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Exceptions\UnknownProviderException;
use RoundlyConsulting\Purchases\Facades\Purchases as PurchasesFacade;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Providers\Provider;
use RoundlyConsulting\Purchases\Purchases;
use RoundlyConsulting\Purchases\Results\GenericResult;

it('resolves a provider through the manager', function (): void {
    config()->set('purchases.providers', [Apple::class]);

    $manager = app(Purchases::class);

    expect($manager->provider('apple'))->toBeInstanceOf(Apple::class)
        ->and($manager->has('apple'))->toBeTrue()
        ->and($manager->has('missing'))->toBeFalse()
        ->and($manager->ids())->toContain('apple');
});

it('throws for an unknown provider', function (): void {
    config()->set('purchases.providers', []);

    app(Purchases::class)->provider('nope');
})->throws(UnknownProviderException::class);

it('lists providers as a keyed collection', function (): void {
    config()->set('purchases.providers', [Apple::class]);

    expect(app(Purchases::class)->providers()->keys()->all())->toBe(['apple']);
});

it('resolves the manager through the facade', function (): void {
    config()->set('purchases.providers', [Apple::class]);

    expect(PurchasesFacade::has('apple'))->toBeTrue();
});

it('decodes and persists through handle', function (): void {
    Event::fake();

    config()->set('purchases.providers', [StubResultProvider::class]);

    $model = app(Purchases::class)->handle('stub', new Request);

    expect($model)->toBeInstanceOf(Subscription::class)
        ->and($model->provider_id)->toBe('stub-1');

    Event::assertDispatched(SubscriptionStarted::class);
});

it('returns a result without persisting through result', function (): void {
    config()->set('purchases.providers', [StubResultProvider::class]);

    $result = app(Purchases::class)->result('stub', new Request);

    expect($result->type())->toBe(ResultType::Subscription)
        ->and(Subscription::query()->count())->toBe(0);
});

class StubResultProvider extends BaseProvider implements Provider
{
    public function id(): string
    {
        return 'stub';
    }

    public function result(Request $request): ProviderResult
    {
        return new GenericResult(
            provider: 'stub',
            type: ResultType::Subscription,
            providerId: 'stub-1',
            status: Status::Completed,
            name: 'pro.monthly',
        );
    }
}
