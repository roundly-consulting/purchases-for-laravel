<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Results\GenericResult;

/*
 * Every store reports its dates in UTC (epoch seconds, epoch milliseconds, RFC 3339 `Z`).
 * Eloquent stores a date as the wall-clock time of the instance it is given and reads it
 * back in the application timezone — so on a host whose `app.timezone` is not UTC, a date
 * that was not converted first comes back shifted by the offset.
 */

beforeEach(function (): void {
    $this->hostTimezone = date_default_timezone_get();

    date_default_timezone_set('Europe/Bratislava');
    config()->set('app.timezone', 'Europe/Bratislava');
});

afterEach(function (): void {
    date_default_timezone_set($this->hostTimezone);
    config()->set('app.timezone', $this->hostTimezone);
    Carbon::setTestNow();
});

it('keeps a stripe subscription\'s dates on their instants', function (int $start, int $trialEnd, int $end): void {
    config()->set('purchases.settings.stripe.webhook_secret', 'whsec_test');

    $result = (new Stripe)->result(stripeSignedRequest([
        'id' => 'evt_tz',
        'type' => 'customer.subscription.updated',
        'created' => $start,
        'data' => ['object' => [
            'id' => 'sub_tz',
            'status' => 'active',
            'current_period_start' => $start,
            'current_period_end' => $end,
            'trial_end' => $trialEnd,
        ]],
    ]));

    app(RecordProviderResultAction::class)->execute($result);

    $subscription = Subscription::query()->sole();

    expect($subscription->active_from?->getTimestamp())->toBe($start)
        ->and($subscription->trial_ends_at?->getTimestamp())->toBe($trialEnd)
        ->and($subscription->ends_at?->getTimestamp())->toBe($end);

    // An hour before the real expiry the customer still has access.
    Carbon::setTestNow(Carbon::createFromTimestamp($end - 3600));

    expect($subscription->isActive())->toBeTrue()
        ->and(Subscription::query()->active()->count())->toBe(1);
})->with([
    'winter (CET, +1)' => [1_700_000_000, 1_700_600_000, 1_702_592_000],
    'summer (CEST, +2)' => [1_715_000_000, 1_715_600_000, 1_717_592_000],
]);

it('keeps an apple refund\'s date on its instant', function (): void {
    config()->set('purchases.settings.apple.bundle_id', 'com.example.app');
    config()->set('purchases.settings.apple.app_apple_id', '1');
    config()->set('purchases.settings.apple.sandbox', false);

    $jws = new class extends JwsManager
    {
        public function __construct() {}

        public function verify(string|DecodedToken $payload): void {}

        public function parse(string $payload): DecodedToken
        {
            $claims = $payload === 'token'
                ? ['notificationUUID' => 'n-tz', 'notificationType' => 'REFUND', 'version' => '2.0', 'signedDate' => 1_717_592_100_000, 'data' => [
                    'appAppleId' => 1, 'bundleId' => 'com.example.app', 'bundleVersion' => '1.0', 'environment' => 'Production', 'signedTransactionInfo' => 'transaction.jws',
                ]]
                : ['bundleId' => 'com.example.app', 'environment' => 'Production', 'transactionId' => 'txn-tz', 'originalTransactionId' => 'orig-tz',
                    'productId' => 'coins.100', 'type' => 'Consumable', 'purchaseDate' => 1_717_000_000_000, 'revocationDate' => 1_717_592_000_000];

            return new DecodedToken(header: ['alg' => 'ES256'], claims: $claims, compact: $payload);
        }
    };

    app(RecordProviderResultAction::class)->execute((new Apple($jws))->result(new Request(['signedPayload' => 'token'])));

    expect(PurchaseRefund::query()->sole()->refunded_at?->getTimestamp())->toBe(1_717_592_000);
});

it('keeps an RFC 3339 date on its instant', function (): void {
    app(RecordProviderResultAction::class)->execute(new GenericResult(
        provider: 'google',
        type: ResultType::Subscription,
        providerId: 'tok-tz',
        status: Status::Completed,
        activeFrom: Carbon::parse('2024-06-01T10:00:00Z'),
        endsAt: Carbon::parse('2024-07-01T10:00:00.250Z'),
    ));

    $subscription = Subscription::query()->sole();

    expect($subscription->active_from?->getTimestamp())->toBe(Carbon::parse('2024-06-01T10:00:00Z')->getTimestamp())
        ->and($subscription->ends_at?->getTimestamp())->toBe(Carbon::parse('2024-07-01T10:00:00Z')->getTimestamp());
});

it('records the same instants when an audited notification is replayed', function (): void {
    $notification = auditedNotification(new GenericResult(
        provider: 'google',
        type: ResultType::Subscription,
        providerId: 'tok-replay',
        status: Status::Completed,
        activeFrom: Carbon::createFromTimestamp(1_715_000_000),
        trialEndsAt: Carbon::createFromTimestamp(1_715_600_000),
        endsAt: Carbon::createFromTimestamp(1_717_592_000),
    ));

    Purchases::replay($notification);

    $subscription = Subscription::query()->sole();

    expect($subscription->active_from?->getTimestamp())->toBe(1_715_000_000)
        ->and($subscription->trial_ends_at?->getTimestamp())->toBe(1_715_600_000)
        ->and($subscription->ends_at?->getTimestamp())->toBe(1_717_592_000);
});
