<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Purchases\Enum\ResultType;

it('exposes the expected result-type values and count', function (): void {
    expect(ResultType::values()->all())->toBe([
        'purchase', 'subscription', 'refund', 'notification', 'unknown',
    ])
        ->and(ResultType::count())->toBe(5)
        ->and(ResultType::names()->all())->toBe([
            'Purchase', 'Subscription', 'Refund', 'Notification', 'Unknown',
        ]);
});

it('builds a validation rule from the backed values', function (): void {
    expect(ResultType::validationRule())
        ->toBe('in:purchase,subscription,refund,notification,unknown');
});

it('builds select options and a value=>label map', function (): void {
    $options = ResultType::options();

    expect($options)->toHaveCount(5)
        ->and($options->first())->toBeInstanceOf(EnumOption::class)
        ->and($options->first()->value)->toBe('purchase')
        ->and($options->first()->label)->toBe('Purchase');

    expect(ResultType::toOptions()->all())->toBe([
        'purchase' => 'Purchase',
        'subscription' => 'Subscription',
        'refund' => 'Refund',
        'notification' => 'Notification',
        'unknown' => 'Unknown',
    ]);
});

it('resolves a case by its label', function (): void {
    expect(ResultType::tryFromLabel('Notification'))->toBe(ResultType::Notification)
        ->and(ResultType::tryFromLabel('Nope'))->toBeNull();
});

it('still resolves the native tryFrom used by the notification factory', function (): void {
    expect(ResultType::tryFrom('subscription'))->toBe(ResultType::Subscription)
        ->and(ResultType::tryFrom('bogus'))->toBeNull();
});
