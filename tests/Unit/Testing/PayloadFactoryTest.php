<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Support\Base64Url;
use RoundlyConsulting\Purchases\Testing\PayloadFactory;

it('builds an apple notification payload', function (): void {
    $payload = PayloadFactory::appleNotification('REFUND', 'VOLUNTARY', 'tx-9');

    expect($payload['notificationType'])->toBe('REFUND')
        ->and($payload['subtype'])->toBe('VOLUNTARY')
        ->and($payload['data']['transactionInfo']['transactionId'])->toBe('tx-9');
});

it('wraps a google notification in a pub/sub envelope', function (): void {
    $notification = PayloadFactory::googleSubscriptionNotification(2, 'tok-7');
    $envelope = PayloadFactory::googleEnvelope($notification);

    $decoded = json_decode(Base64Url::decode($envelope['message']['data']), true);

    expect($decoded['subscriptionNotification']['purchaseToken'])->toBe('tok-7')
        ->and($decoded['subscriptionNotification']['notificationType'])->toBe(2);
});

it('builds a google voided notification', function (): void {
    $notification = PayloadFactory::googleVoidedNotification('GPA.9', 'tok-9');

    expect($notification['voidedPurchaseNotification']['orderId'])->toBe('GPA.9')
        ->and($notification['voidedPurchaseNotification']['purchaseToken'])->toBe('tok-9');
});

it('builds a stripe event envelope', function (): void {
    $event = PayloadFactory::stripeEvent('charge.refunded', ['id' => 'ch_1']);

    expect($event['type'])->toBe('charge.refunded')
        ->and($event['data']['object']['id'])->toBe('ch_1')
        ->and($event['id'])->toStartWith('evt_');
});
