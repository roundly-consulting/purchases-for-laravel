<?php

declare(strict_types=1);

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
