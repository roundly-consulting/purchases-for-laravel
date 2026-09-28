<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Enum\NotificationOrigin;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;

it('exposes the two origins', function (): void {
    expect(NotificationOrigin::values()->all())->toBe(['provider', 'host'])
        ->and(NotificationOrigin::validationRule())->toBe('in:provider,host');
});

it('reads a missing or unknown marker as Provider, never as Host', function (array $payload): void {
    $notification = new PurchaseNotification(['payload' => $payload]);

    expect($notification->origin())->toBe(NotificationOrigin::Provider);
})->with([
    'no marker' => [['provider_id' => 'x']],
    'unknown marker' => [['origin' => 'somebody']],
    'non-string marker' => [['origin' => ['host']]],
]);

it('reads the host marker', function (): void {
    expect((new PurchaseNotification(['payload' => ['origin' => 'host']]))->origin())->toBe(NotificationOrigin::Host);
});
