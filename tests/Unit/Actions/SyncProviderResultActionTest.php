<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Testing\FakeResult;

it('audits, records and marks the audit row processed', function (): void {
    Event::fake([PurchaseRefunded::class]);

    $refund = app(SyncProviderResultAction::class)->execute(FakeResult::refund('stripe', 're_sync'));

    $audit = PurchaseNotification::query()->sole();

    expect($refund)->toBeInstanceOf(PurchaseRefund::class)
        ->and($audit->type)->toBe('refund')
        ->and($audit->processed_at)->not->toBeNull();

    Event::assertDispatched(PurchaseRefunded::class);
});

it('records without an audit row when auditing is off', function (): void {
    config()->set('purchases.audit.enabled', false);

    $refund = app(SyncProviderResultAction::class)->execute(FakeResult::refund('stripe', 're_quiet'));

    expect($refund)->toBeInstanceOf(PurchaseRefund::class)
        ->and(PurchaseNotification::query()->count())->toBe(0);
});

it('leaves the audit row pending when recording fails, so it can be replayed', function (): void {
    Event::listen(PurchaseRefunded::class, function (): never {
        throw new RuntimeException('listener down');
    });

    expect(fn () => app(SyncProviderResultAction::class)->execute(FakeResult::refund('stripe', 're_fail')))
        ->toThrow(RuntimeException::class, 'listener down')
        ->and(PurchaseNotification::query()->pending()->count())->toBe(1);
});
