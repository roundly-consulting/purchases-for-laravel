<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\Exception as PurchasesException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeApiException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeAuthenticationException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeCardException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeConnectionException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeIdempotencyException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeInvalidRequestException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeRateLimitException;
use RoundlyConsulting\Purchases\Exceptions\UnsupportedCapabilityException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\PurchasesManager;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_51SecretKeyThatMustNeverLeak');
    Http::preventStrayRequests();
});

/**
 * The exception a resume of `sub_1` throws for a Stripe answer.
 *
 * @param  array<string, mixed>  $body
 */
function errorsTestFailure(int $status, array $body, array $headers = []): StripeException
{
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($body, $status, $headers)]);

    try {
        Purchases::stripe()->subscriptions()->resume('sub_1', 'resume-key');
    } catch (StripeException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a StripeException.');
}

it('throws a typed exception for each kind of stripe failure', function (int $status, array $error, string $class): void {
    $exception = errorsTestFailure($status, ['error' => $error], ['Request-Id' => 'req_123']);

    expect($exception)->toBeInstanceOf($class)
        ->and($exception)->toBeInstanceOf(PurchasesException::class)
        ->and($exception->httpStatus)->toBe($status)
        ->and($exception->getCode())->toBe($status)
        ->and($exception->stripeType)->toBe($error['type'] ?? null)
        ->and($exception->stripeCode)->toBe($error['code'] ?? null)
        ->and($exception->requestId)->toBe('req_123')
        ->and($exception->idempotencyKey)->toBe('resume-key')
        ->and($exception->getMessage())->toBe($error['message'] ?? "Stripe answered HTTP {$status}.")
        ->and($exception->getPrevious())->toBeNull();
})->with([
    'card declined' => [402, ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.'], StripeCardException::class],
    'bad parameter' => [400, ['type' => 'invalid_request_error', 'code' => 'parameter_invalid_integer', 'param' => 'quantity', 'message' => 'Invalid integer: x'], StripeInvalidRequestException::class],
    'unknown id' => [404, ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such subscription: sub_1'], StripeInvalidRequestException::class],
    'refused key' => [401, ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided.'], StripeAuthenticationException::class],
    'missing permission' => [403, ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions.'], StripeAuthenticationException::class],
    'rate limited' => [429, ['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'Too many requests.'], StripeRateLimitException::class],
    'key reused' => [400, ['type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters.'], StripeIdempotencyException::class],
    'conflict' => [409, ['type' => 'invalid_request_error', 'code' => 'lock_timeout', 'message' => 'This object cannot be accessed right now.'], StripeApiException::class],
    'stripe down' => [500, ['type' => 'api_error', 'message' => 'Something went wrong.'], StripeApiException::class],
    'no error body' => [503, [], StripeApiException::class],
]);

it('keeps the decline code of a declined card', function (): void {
    expect(errorsTestFailure(402, ['error' => ['type' => 'card_error', 'decline_code' => 'insufficient_funds', 'message' => 'Declined.']])->declineCode)
        ->toBe('insufficient_funds');
});

it('keeps the parameter stripe blamed', function (): void {
    expect(errorsTestFailure(400, ['error' => ['type' => 'invalid_request_error', 'param' => 'items[0][quantity]', 'message' => 'Bad.']])->param)
        ->toBe('items[0][quantity]');
});

it('never puts the secret key in an exception', function (): void {
    $exception = errorsTestFailure(401, ['error' => [
        'type' => 'invalid_request_error',
        'message' => 'Invalid API Key provided: sk_test_51SecretKeyThatMustNeverLeak, and sk_live_OtherKey123 or rk_test_abc and whsec_abc',
    ]]);

    expect($exception->getMessage())->toBe('Invalid API Key provided: [redacted], and [redacted] or [redacted] and [redacted]')
        ->and((string) $exception)->not->toContain('SecretKeyThatMustNeverLeak');

    // No frame of the package holds the secret — nor the HTTP response, whose request carries
    // it as the Authorization header. (The test runner's own frames, above, are not ours.)
    $ours = array_filter($exception->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'RoundlyConsulting\\Purchases\\'));

    expect($ours)->not->toBe([])
        ->and(print_r(array_column($ours, 'args'), true))->not->toContain('SecretKeyThatMustNeverLeak')
        ->and(print_r(array_column($ours, 'args'), true))->not->toContain('Authorization');
});

it('throws a connection exception when stripe cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for sk_test_51SecretKeyThatMustNeverLeak'));

    try {
        Purchases::stripe()->subscriptions()->resume('sub_1', 'resume-key');
        $this->fail('Expected a StripeConnectionException.');
    } catch (StripeConnectionException $e) {
        expect($e->getMessage())->toBe('Stripe could not be reached: cURL error 28: Operation timed out for [redacted]')
            ->and($e->idempotencyKey)->toBe('resume-key')
            ->and($e->httpStatus)->toBeNull();
    }
});

it('throws when stripe answers with something unreadable', function (mixed $body): void {
    Http::fake(['api.stripe.com/v1/*' => Http::response($body, 200)]);

    expect(fn () => Purchases::stripe()->subscriptions()->resume('sub_1'))->toThrow(StripeApiException::class, "Stripe's answer could not be read");
})->with([
    'not json' => ['<html>gateway</html>'],
    'a json list' => [[1, 2]],
    'not a subscription' => [['id' => 'cus_1', 'object' => 'customer']],
    'no id' => [['object' => 'subscription']],
]);

it('refuses a billing call without a secret key', function (): void {
    config()->set('purchases.settings.stripe.secret', '');
    Http::fake();

    expect(fn () => Purchases::stripe()->subscriptions()->resume('sub_1'))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.stripe.secret] is not set');
    Http::assertNothingSent();
});

it('bills only through a registered stripe provider', function (): void {
    expect(Purchases::canBill('stripe'))->toBeTrue()
        ->and(Purchases::canBill('apple'))->toBeFalse()
        ->and(Purchases::canBill('google'))->toBeFalse()
        ->and(Purchases::canBill('nope'))->toBeFalse();

    config()->set('purchases.providers', [Apple::class, Google::class]);
    app()->forgetScopedInstances();
    app()->forgetInstance(PurchasesManager::class);
    Purchases::clearResolvedInstances();

    expect(Purchases::canBill('stripe'))->toBeFalse()
        ->and(fn () => Purchases::stripe())->toThrow(UnsupportedCapabilityException::class, 'does not support outbound billing');
});

it('encodes booleans as words and drops unset values', function (): void {
    expect(BillingApi::encode(['a' => true, 'b' => false, 'c' => null, 'd' => ['e' => true, 'f' => null, 'g' => 3]]))
        ->toBe(['a' => 'true', 'b' => 'false', 'd' => ['e' => 'true', 'g' => 3]]);
});
