<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\AppMetadata;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

it('reads a value and tracks retrieved keys', function (): void {
    $dataset = new DataSet(['a' => 1, 'b' => 2]);

    expect($dataset->value('a'))->toBe(1)
        ->and($dataset->value('missing', 'fallback'))->toBe('fallback')
        ->and($dataset->retrieved())->toBe(['a' => 1]);
});

it('casts integers with a default', function (): void {
    $dataset = new DataSet(['count' => '42']);

    expect($dataset->int('count'))->toBe(42)
        ->and($dataset->int('absent', 7))->toBe(7);
});

it('resolves backed enums or returns the default', function (): void {
    $dataset = new DataSet(['status' => 'completed', 'bad' => 'nope']);

    expect($dataset->enum('status', Status::class))->toBe(Status::Completed)
        ->and($dataset->enum('bad', Status::class, Status::New))->toBe(Status::New)
        ->and($dataset->enum('absent', Status::class))->toBeNull();
});

it('returns the default for a non scalar enum value', function (): void {
    $dataset = new DataSet(['status' => ['array']]);

    expect($dataset->enum('status', Status::class, Status::Failed))->toBe(Status::Failed);
});

it('parses boolean values from strings and natives', function (): void {
    $dataset = new DataSet(['s' => 'true', 'f' => 'FALSE', 'n' => 1]);

    expect($dataset->bool('s'))->toBeTrue()
        ->and($dataset->bool('f'))->toBeFalse()
        ->and($dataset->bool('n'))->toBeTrue()
        ->and($dataset->bool('absent', true))->toBeTrue();
});

it('converts millisecond timestamps to carbon or null', function (): void {
    $dataset = new DataSet(['when' => 1700000000000, 'bad' => 'not-a-number']);

    expect($dataset->datetime('when'))->toBeInstanceOf(CarbonInterface::class)
        ->and($dataset->datetime('bad'))->toBeNull()
        ->and($dataset->datetime('absent'))->toBeNull();
});

it('maps an array of items to value objects via fromRaw', function (): void {
    $dataset = new DataSet([
        'prices' => [['amount' => 100], ['amount' => 200]],
    ]);

    $monies = $dataset->arrayOf('prices', MoneyBag::class);

    expect($monies)->toHaveCount(2)
        ->and($monies[0])->toBeInstanceOf(MoneyBag::class)
        ->and($monies[0]->payload)->toBe(['amount' => 100]);
});

it('returns an empty list when arrayOf target is not an array', function (): void {
    $dataset = new DataSet(['prices' => 'scalar']);

    expect($dataset->arrayOf('prices', MoneyBag::class))->toBe([]);
});

it('builds a single value object via valueOf', function (): void {
    $dataset = new DataSet(['bag' => ['k' => 'v']]);

    expect($dataset->valueOf('bag', MoneyBag::class))->toBeInstanceOf(MoneyBag::class)
        ->and($dataset->valueOf('absent', MoneyBag::class, 'default'))->toBe('default');
});

it('delegates to a fromRaw factory', function (): void {
    $dataset = new DataSet([
        'data' => [
            'appAppleId' => '1',
            'bundleId' => 'com.test',
            'bundleVersion' => '2',
            'environment' => 'Production',
        ],
    ]);

    expect($dataset->fromRawTo('data', AppMetadata::class))->toBeInstanceOf(AppMetadata::class)
        ->and($dataset->fromRawTo('absent', AppMetadata::class, 'default'))->toBe('default');
});

it('does not duplicate retrieved keys', function (): void {
    $dataset = new DataSet(['a' => 1]);

    $dataset->value('a');
    $dataset->value('a');

    expect($dataset->retrieved())->toBe(['a' => 1]);
});

/**
 * A tiny single-argument value object used to exercise arrayOf/valueOf.
 */
final class MoneyBag implements FromRaw
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self($raw);
    }

    public function unused(): Money
    {
        return Money::ofMinor(0, 'USD');
    }
}
