<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Enums\Exceptions\EnumException;
use RoundlyConsulting\Purchases\Enum\Status;

it('exposes the expected purchase statuses', function (): void {
    expect(Status::New->value)->toBe('new')
        ->and(Status::Pending->value)->toBe('pending')
        ->and(Status::Processing->value)->toBe('processing')
        ->and(Status::Completed->value)->toBe('completed')
        ->and(Status::Failed->value)->toBe('failed')
        ->and(Status::Canceled->value)->toBe('canceled');
});

it('resolves a status from its backing value', function (): void {
    expect(Status::tryFrom('completed'))->toBe(Status::Completed)
        ->and(Status::tryFrom('unknown'))->toBeNull();
});

it('exposes the additive billing-retry statuses', function (): void {
    expect(Status::InGracePeriod->value)->toBe('in_grace')
        ->and(Status::OnHold->value)->toBe('on_hold')
        ->and(Status::Refunded->value)->toBe('refunded');
});

it('exposes every backed value and case name via the enums trait', function (): void {
    expect(Status::values()->all())->toBe([
        'new', 'pending', 'processing', 'completed', 'failed', 'canceled', 'in_grace', 'on_hold', 'refunded',
    ])
        ->and(Status::names()->all())->toBe([
            'New', 'Pending', 'Processing', 'Completed', 'Failed', 'Canceled', 'InGracePeriod', 'OnHold', 'Refunded',
        ])
        ->and(Status::count())->toBe(9);
});

it('derives readable labels for multi-word values', function (): void {
    expect(Status::labels()->all())->toContain('In Grace', 'On Hold')
        ->and(Status::InGracePeriod->readable())->toBe('In Grace')
        ->and(Status::OnHold->label())->toBe('On Hold');
});

it('builds select options and a value=>label map', function (): void {
    $options = Status::options();

    expect($options)->toHaveCount(9)
        ->and($options->first())->toBeInstanceOf(EnumOption::class)
        ->and($options->first()->value)->toBe('new')
        ->and($options->first()->label)->toBe('New')
        ->and($options->first()->name)->toBe('New');

    $map = Status::toOptions();

    expect($map)->toHaveCount(9)
        ->and($map->get('in_grace'))->toBe('In Grace')
        ->and($map->get('on_hold'))->toBe('On Hold');
});

it('builds a validation rule from the backed values', function (): void {
    expect(Status::validationRule())
        ->toBe('in:new,pending,processing,completed,failed,canceled,in_grace,on_hold,refunded');
});

it('resolves cases by name and label', function (): void {
    expect(Status::tryFromName('InGracePeriod'))->toBe(Status::InGracePeriod)
        ->and(Status::tryFromName('Nope'))->toBeNull()
        ->and(Status::tryFromLabel('On Hold'))->toBe(Status::OnHold)
        ->and(Status::hasValue('in_grace'))->toBeTrue()
        ->and(Status::hasValue('x'))->toBeFalse();
});

it('throws when resolving an unknown name or label', function (): void {
    expect(fn (): Status => Status::fromName('Missing'))->toThrow(EnumException::class)
        ->and(fn (): Status => Status::fromLabel('Missing'))->toThrow(EnumException::class);
});

it('compares cases with instance helpers', function (): void {
    expect(Status::Completed->is(Status::Completed))->toBeTrue()
        ->and(Status::Completed->isIn([Status::Completed, Status::InGracePeriod]))->toBeTrue()
        ->and(Status::Failed->isIn([Status::Completed, Status::InGracePeriod]))->toBeFalse();
});

it('treats completed and grace period as active entitlements', function (Status $status, bool $active): void {
    expect($status->isActive())->toBe($active);
})->with([
    [Status::Completed, true],
    [Status::InGracePeriod, true],
    [Status::OnHold, false],
    [Status::Refunded, false],
    [Status::Canceled, false],
    [Status::Failed, false],
    [Status::Pending, false],
    [Status::New, false],
    [Status::Processing, false],
]);
