<?php

declare(strict_types=1);

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Testing\TestCertificateChain;
use RoundlyConsulting\Crypto\Testing\TestCertificates;
use RoundlyConsulting\Crypto\Testing\TestLeafOptions;
use RoundlyConsulting\Crypto\X509\Certificate;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;

/*
 | Apple-shaped chains minted by crypto's TestCertificates: a self-signed root,
 | an intermediate it signs, and a leaf the intermediate signs. Apple cannot sign
 | anything for us, so the positive trust path is exercised against a throwaway CA
 | pinned through the verifier's `fingerprints()` seam — every other rule (chain
 | length, linkage, validity, the ES256 pin) is the production code's.
 */

/**
 * Mint a genuine App-Store-shaped ES256 notification signed by the chain's leaf.
 *
 * @param  array<string, mixed>  $claims
 * @param  list<string>|null  $x5c
 */
function signWithChain(TestCertificateChain $chain, array $claims, ?array $x5c = null, ?EcKey $key = null): string
{
    $leafKey = $key ?? $chain->leafKey;

    expect($leafKey)->toBeInstanceOf(EcKey::class);

    return (new Jws)->sign(['x5c' => $x5c ?? $chain->x5c()], $claims, new Es($leafKey));
}

function verifierPinnedTo(TestCertificateChain $chain): JwsVerifier
{
    // The verifier pins SHA-1 fingerprints, as Apple's chain is pinned.
    return appleVerifierPinnedTo($chain->pinnedFingerprints(HashAlgorithm::Sha1));
}

/**
 * The verifier pinned to the committed Apple-shaped fixture chain, whose leaf and
 * intermediate carry Apple's App Store signing markers (TestCertificates cannot put an
 * extension on an intermediate, so a positive path needs the committed chain).
 */
function fixtureVerifier(string $intermediate = 'intermediate.pem'): JwsVerifier
{
    return appleVerifierPinnedTo([
        Certificate::fromPem(appleFixture($intermediate))->fingerprint(HashAlgorithm::Sha1),
        Certificate::fromPem(appleFixture('root.pem'))->fingerprint(HashAlgorithm::Sha1),
    ]);
}

it('verifies a genuine apple notification end to end', function (): void {
    $compact = appleFixtureToken(['notificationUUID' => 'n-1', 'notificationType' => 'DID_RENEW'], appleFixtureChain());

    $manager = new JwsManager(fixtureVerifier());

    $manager->verify($compact);

    expect($manager->parse($compact)->claims['notificationUUID'])->toBe('n-1');
});

it('pins the intermediate and root, never the leaf', function (): void {
    $chain = TestCertificates::chain();

    // The verifier compares the chain minus the leaf, in leaf → root order: the
    // leaf rotates constantly and is never an anchor.
    expect($chain->pinnedFingerprints())
        ->toBe(array_slice($chain->fingerprints(), 1))
        ->toHaveCount(2);
});

it('rejects a notification whose payload was tampered with', function (): void {
    $compact = appleFixtureToken(['notificationUUID' => 'n-1', 'transactionId' => 'tx-1'], appleFixtureChain());

    // Swap the payload segment for a forged one, keeping header and signature.
    [$header, , $signature] = explode('.', $compact);
    $forged = $header
        .'.'.Base64Url::encode('{"notificationUUID":"n-1","transactionId":"tx-EVIL"}')
        .'.'.$signature;

    (new JwsManager(fixtureVerifier()))->verify($forged);
})->throws(VerificationException::class, 'Payload verification failed.');

/*
 | Apple's CA issues leaves for many purposes (code signing, push, Wallet). Only a leaf
 | carrying the App Store signing marker, under an intermediate carrying the WWDR
 | marker, may sign a notification — Apple's reference ChainVerifier checks both.
 */
it('rejects a leaf without apple\'s app store signing marker', function (): void {
    $x5c = [appleFixtureX5c('no-oid-leaf.pem'), appleFixtureX5c('intermediate.pem'), appleFixtureX5c('root.pem')];

    // Pinned anchors, a linked chain, inside its validity window, signed by the leaf key.
    (new JwsManager(fixtureVerifier()))->verify(appleFixtureToken(['notificationUUID' => 'n-1'], $x5c));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects an intermediate without apple\'s wwdr marker', function (): void {
    $x5c = [
        appleFixtureX5c('no-oid-intermediate-leaf.pem'),
        appleFixtureX5c('no-oid-intermediate.pem'),
        appleFixtureX5c('root.pem'),
    ];

    // Even pinned as the anchor, an intermediate without the marker is not Apple's WWDR CA.
    (new JwsManager(fixtureVerifier('no-oid-intermediate.pem')))->verify(appleFixtureToken(['notificationUUID' => 'n-1'], $x5c));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a pinned chain that carries no apple markers at all', function (): void {
    $chain = TestCertificates::chain();

    expect(verifierPinnedTo($chain)->verify(new DecodedToken(
        header: ['alg' => 'ES256', 'x5c' => $chain->x5c()],
        claims: [],
        compact: signWithChain($chain, ['notificationUUID' => 'n-1']),
    )))->toBeFalse();
});

it('still rejects a minted chain whose intermediate lacks the wwdr marker', function (): void {
    // TestCertificates marks the leaf only; the intermediate check still refuses it.
    $chain = TestCertificates::chain(leafOptions: new TestLeafOptions(rawExtensions: ['1.2.840.113635.100.6.11.1' => "\x05\x00"]));

    expect($chain->leaf()->extension('1.2.840.113635.100.6.11.1'))->not->toBeNull()
        ->and(verifierPinnedTo($chain)->verify(new DecodedToken(
            header: ['alg' => 'ES256', 'x5c' => $chain->x5c()],
            claims: [],
            compact: signWithChain($chain, ['notificationUUID' => 'n-1']),
        )))->toBeFalse();
});

it('rejects a chain that does not match the pinned fingerprints', function (): void {
    $chain = TestCertificates::chain();

    // The real verifier pins Apple's WWDR intermediate and G3 root — a
    // perfectly-formed chain from any other CA must not be trusted.
    (new JwsManager)->verify(signWithChain($chain, ['notificationUUID' => 'n-1']));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a leaf that the pinned intermediate did not sign', function (): void {
    $chain = TestCertificates::chain();
    $rogue = TestCertificates::rogueLeaf($chain);

    // Pinned intermediate + root, but the leaf comes from a rogue CA: the
    // fingerprints still match, so only the chain linkage catches this.
    $x5c = [$rogue->base64(), ...array_slice($chain->x5c(), 1)];

    $compact = (new Jws)->sign(['x5c' => $x5c], ['notificationUUID' => 'n-1'], new Es(EcKey::generate()));

    (new JwsManager(verifierPinnedTo($chain)))->verify($compact);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a token signed by a key other than the leaf certificate', function (): void {
    // Genuine, fully trusted chain — but the signature is from a foreign key.
    $compact = appleFixtureToken(['notificationUUID' => 'n-1'], appleFixtureChain(), 'foreign-key.pem');

    (new JwsManager(fixtureVerifier()))->verify($compact);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a chain that is not exactly three certificates', function (): void {
    $chain = TestCertificates::chain();
    $x5c = $chain->x5c();

    expect(verifierPinnedTo($chain)->verify(tokenWithChain([$x5c[0], $x5c[1]])))->toBeFalse()
        ->and(verifierPinnedTo($chain)->verify(tokenWithChain([])))->toBeFalse();
});

it('rejects a chain longer than three certificates', function (): void {
    $chain = TestCertificates::chain(length: 4);

    expect(verifierPinnedTo($chain)->verify(tokenWithChain($chain->x5c())))->toBeFalse();
});

it('rejects an unreadable certificate in the header', function (): void {
    // A malformed x5c entry now arrives from crypto as a MalformedCertificate /
    // InvalidEncoding exception; it must still reach the caller as ours.
    verifierPinnedTo(TestCertificates::chain())->verify(tokenWithChain(['not-a-cert', 'not-a-cert', 'not-a-cert']));
})->throws(VerificationException::class, 'Unable to read certificate from JWS header.');

it('rejects an oversized certificate in the header', function (): void {
    $chain = TestCertificates::chain();
    $x5c = $chain->x5c();
    $x5c[0] = base64_encode(str_repeat('A', Certificate::MAX_CERTIFICATE_BYTES + 1));

    verifierPinnedTo($chain)->verify(tokenWithChain($x5c));
})->throws(VerificationException::class, 'Unable to read certificate from JWS header.');

/**
 * @param  list<string>  $x5c
 */
function tokenWithChain(array $x5c): DecodedToken
{
    return new DecodedToken(
        header: ['alg' => 'ES256', 'x5c' => $x5c],
        claims: [],
        compact: 'a.b.c',
    );
}
