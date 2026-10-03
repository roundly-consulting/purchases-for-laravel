<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Crypto\X509\Certificate;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;

/*
 |--------------------------------------------------------------------------
 | D1 — Apple certificate validity dates, with a clock-skew leeway
 |--------------------------------------------------------------------------
 |
 | A certificate outside its notBefore..notAfter window is rejected, however
 | genuine its signature. The leeway absorbs a fast/slow host clock and is
 | applied to BOTH bounds.
 |
 | Expiry is exercised by evaluating the chain at another INSTANT rather than
 | by minting a back-dated fixture: ext-openssl always stamps notBefore at
 | signing time, so a wall-clock fixture cannot express "expired" — and the
 | instant is deterministic where a fixture would not be.
 |
 */

/** [intermediate, root] of the genuine committed chain. */
const VALIDITY_PINS = [
    'f66fb600d3e019b8570cb29cb668251b7c41b82b',
    '6e05272e17dc84ad0954bce1e5a85e302aade28a',
];

/** [intermediate, root] of the chain whose INTERMEDIATE expires long before its leaf. */
const SHORT_PINS = [
    '7bdaba16fd8f075e9ab3885333bae3840cc1e0cb',
    '7804354848e6da4797de1563eb59061f91cb241c',
];

afterEach(fn () => CarbonImmutable::setTestNow());

function fixtureCertificate(string $name): Certificate
{
    return Certificate::fromPem(appleFixture($name));
}

function verifyAtSkew(?int $skew = null, ?string $token = null): void
{
    if ($skew !== null) {
        config()->set('purchases.settings.apple.certificate_clock_skew', $skew);
    }

    (new JwsManager(appleVerifierPinnedTo(VALIDITY_PINS)))->verify(
        $token ?? appleFixtureToken(['notificationUUID' => 'n-1'], appleFixtureChain()),
    );
}

it('defaults the clock skew to 60 seconds', function (): void {
    expect(config('purchases.settings.apple.certificate_clock_skew'))->toBe(60);
});

it('accepts a notification while the chain is inside its validity window', function (): void {
    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notBefore()->addDay());

    verifyAtSkew(60);
})->throwsNoExceptions();

it('rejects a chain that expired beyond the leeway', function (): void {
    $leaf = fixtureCertificate('leaf.pem');

    CarbonImmutable::setTestNow($leaf->notAfter()->addSeconds(61));

    verifyAtSkew(60);
})->throws(
    VerificationException::class,
    'Apple certificate [Purchases Freeze Leaf] expired at',
);

it('accepts a chain that expired within the leeway', function (): void {
    $leaf = fixtureCertificate('leaf.pem');

    // 59 seconds past notAfter, with a 60-second skew allowance: a clock that is
    // a minute fast must not start rejecting genuine notifications.
    CarbonImmutable::setTestNow($leaf->notAfter()->addSeconds(59));

    verifyAtSkew(60);
})->throwsNoExceptions();

it('rejects a chain that is not yet valid beyond the leeway', function (): void {
    $leaf = fixtureCertificate('leaf.pem');

    CarbonImmutable::setTestNow($leaf->notBefore()->subSeconds(61));

    verifyAtSkew(60);
})->throws(
    VerificationException::class,
    'Apple certificate [Purchases Freeze Leaf] is not valid before',
);

it('accepts a chain that is not yet valid within the leeway', function (): void {
    $leaf = fixtureCertificate('leaf.pem');

    // The leeway is symmetric: it widens BOTH bounds, not just the far one.
    CarbonImmutable::setTestNow($leaf->notBefore()->subSeconds(59));

    verifyAtSkew(60);
})->throwsNoExceptions();

it('still accepts a chain at the exact instant it expires with a zero leeway', function (): void {
    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter());

    verifyAtSkew(0);
})->throwsNoExceptions();

it('rejects a chain one second past expiry with a zero leeway', function (): void {
    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter()->addSecond());

    verifyAtSkew(0);
})->throws(VerificationException::class, 'expired at');

it('honours a larger configured leeway', function (): void {
    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter()->addMinutes(30));

    verifyAtSkew(3600);
})->throwsNoExceptions();

it('rejects an expired intermediate even when the leaf is still valid', function (): void {
    $x5c = [
        appleFixtureX5c('short-leaf.pem'),
        appleFixtureX5c('short-intermediate.pem'),
        appleFixtureX5c('short-root.pem'),
    ];

    $intermediate = fixtureCertificate('short-intermediate.pem');
    $leaf = fixtureCertificate('short-leaf.pem');

    // The leaf outlives the intermediate that issued it. The ruling covers every
    // certificate in the chain, not just the one that signed the token.
    CarbonImmutable::setTestNow($intermediate->notAfter()->addDay());

    expect($leaf->isValidAt())->toBeTrue();

    (new JwsManager(appleVerifierPinnedTo(SHORT_PINS)))->verify(
        appleFixtureToken(['notificationUUID' => 'n-1'], $x5c, 'short-leaf-key.pem'),
    );
})->throws(
    VerificationException::class,
    'Apple certificate [Purchases Short Intermediate] expired at',
);

it('names the certificate and its notAfter instant in the rejection', function (): void {
    $leaf = fixtureCertificate('leaf.pem');

    CarbonImmutable::setTestNow($leaf->notAfter()->addYear());

    try {
        verifyAtSkew(60);
    } catch (VerificationException $e) {
        // An expired certificate must never be indistinguishable from a forged
        // signature: the message names the certificate and when it lapsed.
        expect($e->getMessage())
            ->toContain('Purchases Freeze Leaf')
            ->toContain($leaf->notAfter()->toIso8601String())
            ->not->toContain('Payload verification failed');

        return;
    }

    $this->fail('An expired chain must be rejected.');
});

it('fails loudly on a negative configured leeway', function (): void {
    verifyAtSkew(-1);
})->throws(
    InvalidConfigurationException::class,
    '[purchases.settings.apple.certificate_clock_skew] must be a whole number of seconds between 0 and 3600; got -1.',
);

it('fails loudly on a leeway large enough to disable the check', function (): void {
    // The fat-fingered 600000: it must not silently turn the expiry check off.
    verifyAtSkew(600000);
})->throws(InvalidConfigurationException::class, 'got 600000.');

it('fails loudly on a leeway that is not a whole number of seconds', function (): void {
    config()->set('purchases.settings.apple.certificate_clock_skew', 'sixty');

    verifyAtSkew();
})->throws(InvalidConfigurationException::class, "got 'sixty'.");

it('fails loudly on a leeway that is not scalar at all', function (): void {
    config()->set('purchases.settings.apple.certificate_clock_skew', ['60']);

    verifyAtSkew();
})->throws(InvalidConfigurationException::class, 'got array.');

it('reads a leeway supplied as an environment string', function (): void {
    // env() hands back strings, so the configured value commonly arrives as one.
    config()->set('purchases.settings.apple.certificate_clock_skew', '120');

    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter()->addSeconds(119));

    verifyAtSkew();
})->throwsNoExceptions();

it('reads a blank leeway as not set, so the 60-second default applies', function (?string $blank): void {
    // A host's `PURCHASES_APPLE_CERTIFICATE_CLOCK_SKEW=` line arrives as an empty string.
    config()->set('purchases.settings.apple.certificate_clock_skew', $blank);

    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter()->addSeconds(59));
    verifyAtSkew();

    CarbonImmutable::setTestNow(fixtureCertificate('leaf.pem')->notAfter()->addSeconds(61));
    expect(fn () => verifyAtSkew())->toThrow(VerificationException::class);
})->with(['absent' => null, 'empty' => '', 'whitespace' => '  ']);
