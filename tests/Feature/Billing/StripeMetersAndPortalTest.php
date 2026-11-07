<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Purchases\Actions\Stripe\ReportStripeMeterEventAction;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterEventData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Jobs\ReportStripeMeterEvent;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MeterAggregation;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_meters');
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function metersTestMeter(array $overrides = []): array
{
    return $overrides + [
        'id' => 'mtr_1',
        'object' => 'billing.meter',
        'display_name' => 'Storage (GB-days)',
        'event_name' => 'storage_gb_days',
        'status' => 'active',
        'default_aggregation' => ['formula' => 'sum'],
        'customer_mapping' => ['type' => 'by_id', 'event_payload_key' => 'stripe_customer_id'],
        'value_settings' => ['event_payload_key' => 'value'],
    ];
}

it('exposes exactly the aggregation formulas stripe supports', function (): void {
    expect(array_map(fn (MeterAggregation $formula): string => $formula->value, MeterAggregation::cases()))->toBe(['count', 'sum', 'last'])
        ->and(MeterAggregation::Sum->describe())->toContain('sum')
        ->and(MeterAggregation::Count->describe())->toContain('number of events')
        ->and(MeterAggregation::Last->describe())->toContain('last event');
});

it('creates a meter with its aggregation and payload keys', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meters' => Http::response(metersTestMeter())]);

    $meter = Purchases::stripe()->meters()->create(new MeterData('storage_gb_days', 'Storage (GB-days)', MeterAggregation::Sum), 'meter-storage');

    expect($meter->id)->toBe('mtr_1')
        ->and($meter->eventName)->toBe('storage_gb_days')
        ->and($meter->displayName)->toBe('Storage (GB-days)')
        ->and($meter->aggregation)->toBe(MeterAggregation::Sum)
        ->and($meter->active)->toBeTrue()
        ->and($meter->customerPayloadKey)->toBe('stripe_customer_id')
        ->and($meter->valuePayloadKey)->toBe('value');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/billing/meters'
        && $request->hasHeader('Idempotency-Key', 'meter-storage')
        && stripeForm($request) === [
            'display_name' => 'Storage (GB-days)',
            'event_name' => 'storage_gb_days',
            'default_aggregation' => ['formula' => 'sum'],
            'customer_mapping' => ['type' => 'by_id', 'event_payload_key' => 'stripe_customer_id'],
            'value_settings' => ['event_payload_key' => 'value'],
        ]);
});

it('creates a count meter without a value key, and a last meter with custom keys', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meters' => Http::sequence()
        ->push(metersTestMeter(['event_name' => 'api_calls', 'default_aggregation' => ['formula' => 'count'], 'value_settings' => null]))
        ->push(metersTestMeter(['event_name' => 'storage_gb', 'default_aggregation' => ['formula' => 'last'], 'customer_mapping' => ['event_payload_key' => 'customer'], 'value_settings' => ['event_payload_key' => 'gb']])),
    ]);

    $count = Purchases::stripe()->meters()->create(new MeterData('api_calls', 'API calls', MeterAggregation::Count));
    $last = Purchases::stripe()->meters()->create(new MeterData('storage_gb', 'Storage', MeterAggregation::Last, 'customer', 'gb'));

    expect($count->aggregation)->toBe(MeterAggregation::Count)
        ->and($count->valuePayloadKey)->toBe('value')
        ->and($last->aggregation)->toBe(MeterAggregation::Last)
        ->and($last->customerPayloadKey)->toBe('customer')
        ->and($last->valuePayloadKey)->toBe('gb');

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['event_name'] === 'api_calls' && ! isset(stripeForm($request)['value_settings']));
    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['event_name'] === 'storage_gb'
        && stripeForm($request)['default_aggregation'] === ['formula' => 'last']
        && stripeForm($request)['value_settings'] === ['event_payload_key' => 'gb']);
});

it('validates a meter before stripe is asked', function (Closure $build): void {
    expect($build)->toThrow(BillingException::class);
})->with([
    'blank event name' => [fn () => new MeterData(' ', 'x')],
    'long event name' => [fn () => new MeterData(str_repeat('e', 101), 'x')],
    'blank display name' => [fn () => new MeterData('e', '')],
]);

it('finds an active meter by event name across pages', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meters*' => Http::sequence()
        ->push(['object' => 'list', 'has_more' => true, 'data' => [metersTestMeter(['id' => 'mtr_a', 'event_name' => 'api_calls'])]])
        ->push(['object' => 'list', 'has_more' => false, 'data' => [metersTestMeter(['id' => 'mtr_b'])]]),
    ]);

    $meter = Purchases::stripe()->meters()->find('storage_gb_days');

    expect($meter?->id)->toBe('mtr_b');
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'status=active')
        && str_contains($request->url(), 'limit=100')
        && ! str_contains($request->url(), 'starting_after'));
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'starting_after=mtr_a'));
});

it('finds nothing for an unknown event name', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meters*' => Http::response(['object' => 'list', 'has_more' => false, 'data' => [metersTestMeter()]])]);

    expect(Purchases::stripe()->meters()->find('nope'))->toBeNull();
});

it('creates a meter only when none is active for the event name', function (): void {
    Http::fake([
        'api.stripe.com/v1/billing/meters?*' => Http::sequence()
            ->push(['object' => 'list', 'has_more' => false, 'data' => [metersTestMeter()]])
            ->push(['object' => 'list', 'has_more' => false, 'data' => []]),
        'api.stripe.com/v1/billing/meters' => Http::response(metersTestMeter(['id' => 'mtr_new'])),
    ]);

    $data = new MeterData('storage_gb_days', 'Storage (GB-days)');

    expect(Purchases::stripe()->meters()->findOrCreate($data)->id)->toBe('mtr_1')
        ->and(Purchases::stripe()->meters()->findOrCreate($data)->id)->toBe('mtr_new');
    Http::assertSentCount(3);
});

it('reports a meter event for a company with its value, timestamp and identifier', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    $at = Carbon::parse('2026-11-07 23:59:00', 'UTC');

    Http::fake(['api.stripe.com/v1/billing/meter_events' => Http::response([
        'object' => 'billing.meter_event', 'event_name' => 'storage_gb_days', 'identifier' => 'storage-acme-2026-11-07',
        'payload' => ['stripe_customer_id' => 'cus_1', 'value' => '12.5'], 'timestamp' => $at->getTimestamp(),
    ])]);

    $event = Purchases::stripe()->meters()->report($company, 'storage_gb_days', 12.5, $at, 'storage-acme-2026-11-07');

    expect($event->identifier)->toBe('storage-acme-2026-11-07')
        ->and($event->payload['value'])->toBe('12.5')
        ->and($event->timestamp?->getTimestamp())->toBe($at->getTimestamp());

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/billing/meter_events'
        && $request->hasHeader('Idempotency-Key', 'storage-acme-2026-11-07')
        && stripeForm($request) === [
            'event_name' => 'storage_gb_days',
            'payload' => ['stripe_customer_id' => 'cus_1', 'value' => '12.5'],
            'identifier' => 'storage-acme-2026-11-07',
            'timestamp' => (string) $at->getTimestamp(),
        ]);
});

it('generates one identifier per report and sends it as the idempotency key too', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meter_events' => Http::response(['event_name' => 'storage_gb_days'])]);

    Purchases::stripe()->meters()->report('cus_1', 'storage_gb_days', 3);

    Http::assertSent(function (HttpRequest $request): bool {
        $form = stripeForm($request);

        return preg_match('/^[0-9a-f-]{36}$/', (string) $form['identifier']) === 1
            && $request->hasHeader('Idempotency-Key', (string) $form['identifier'])
            && $form['payload'] === ['stripe_customer_id' => 'cus_1', 'value' => '3']
            && ! isset($form['timestamp']);
    });
});

it('sends a meter value as an exact decimal string', function (int|float|string $value, string $sent): void {
    expect((new MeterEventData('e', 'cus_1', $value))->value)->toBe($sent);
})->with([
    'integer' => [42, '42'],
    'float' => [12.5, '12.5'],
    'float with binary noise' => [0.1 + 0.2, '0.3'],
    'decimal string' => ['0.000123', '0.000123'],
    'negative zero' => [-0.0, '0'],
]);

it('refuses a meter event it cannot send', function (Closure $build, string $message): void {
    expect($build)->toThrow(BillingException::class, $message);
})->with([
    'not a number' => [fn () => new MeterEventData('e', 'cus_1', 'twelve'), 'must be a number'],
    'infinite' => [fn () => new MeterEventData('e', 'cus_1', INF), 'finite'],
    'blank customer' => [fn () => new MeterEventData('e', ' ', 1), 'Stripe customer'],
    'long identifier' => [fn () => new MeterEventData('e', 'cus_1', 1, identifier: str_repeat('i', 101)), 'identifier'],
]);

it('refuses to report usage for an owner without a stripe customer', function (): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->meters()->report(Organization::query()->create(['name' => 'x']), 'storage_gb_days', 1))
        ->toThrow(BillingException::class, 'has no Stripe customer');
    Http::assertNothingSent();
});

it('queues a meter report on the package queue, stamped and identified when queued', function (): void {
    Queue::fake();
    config()->set('purchases.queue.connection', 'redis');
    config()->set('purchases.queue.queue', 'billing');
    Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    $event = Purchases::stripe()->meters()->queue($company, 'storage_gb_days', 7);

    expect($event->customer)->toBe('cus_1')
        ->and($event->timestamp?->getTimestamp())->toBe(Carbon::now()->getTimestamp());

    Queue::assertPushed(ReportStripeMeterEvent::class, fn (ReportStripeMeterEvent $job): bool => $job->event === $event
        && $job->connection === 'redis'
        && $job->queue === 'billing');
});

it('reports a queued event once, however often the job runs', function (): void {
    Http::fake(['api.stripe.com/v1/billing/meter_events' => Http::response(['event_name' => 'storage_gb_days'])]);
    $job = new ReportStripeMeterEvent(new MeterEventData('storage_gb_days', 'cus_1', 7, Carbon::parse('2026-11-07 12:00', 'UTC')));

    // A retry re-runs the same serialized job: the identifier (and idempotency key) repeat.
    unserialize(serialize($job))->handle(app(ReportStripeMeterEventAction::class));
    unserialize(serialize($job))->handle(app(ReportStripeMeterEventAction::class));

    $identifiers = Http::recorded()->map(fn (array $pair): string => (string) stripeForm($pair[0])['identifier'])->unique();

    expect($identifiers)->toHaveCount(1)
        ->and($identifiers->first())->toBe($job->event->identifier);
    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['timestamp'] === (string) Carbon::parse('2026-11-07 12:00', 'UTC')->getTimestamp());
});

it('opens a customer portal session for a company', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake(['api.stripe.com/v1/billing_portal/sessions' => Http::response([
        'id' => 'bps_1', 'object' => 'billing_portal.session', 'customer' => 'cus_1',
        'return_url' => 'https://app.test/settings/billing', 'url' => 'https://billing.stripe.com/p/session/test_1',
    ])]);

    $session = Purchases::stripe()->portal($company, 'https://app.test/settings/billing', 'bpc_1', 'portal-acme');

    expect($session->url)->toBe('https://billing.stripe.com/p/session/test_1')
        ->and($session->id)->toBe('bps_1')
        ->and($session->customer)->toBe('cus_1')
        ->and($session->returnUrl)->toBe('https://app.test/settings/billing');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
        && $request->hasHeader('Idempotency-Key', 'portal-acme')
        && stripeForm($request) === ['customer' => 'cus_1', 'return_url' => 'https://app.test/settings/billing', 'configuration' => 'bpc_1']);
});

it('refuses a portal session for an owner without a stripe customer', function (): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->portal(Organization::query()->create(['name' => 'x'])))
        ->toThrow(BillingException::class, 'has no Stripe customer');
    Http::assertNothingSent();
});
