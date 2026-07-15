<?php

declare(strict_types=1);

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Hash\Hmac;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;

/*
 |--------------------------------------------------------------------------
 | Frozen Apple trust vectors
 |--------------------------------------------------------------------------
 |
 | Everything here was computed from the openssl_x509_* engine that shipped
 | before the crypto X.509 retrofit, and is asserted against COMMITTED
 | certificate fixtures (tests/Fixtures/apple) rather than freshly minted
 | ones — so these vectors are engine-independent and stay valid across the
 | swap. Apple's chain trust is what stops a forged App Store notification:
 | if any of this moves, real notifications stop verifying (or worse, a
 | forged one starts).
 |
 */

/** SHA-1 fingerprints of the committed fixture chain, lower-case hex, leaf → root. */
const FROZEN_CHAIN_FINGERPRINTS = [
    '53c75aa63e2d369532b65e158677646788544eb3', // leaf
    '53d383651ef60004ae0fd68877e2edd1568446a5', // intermediate
    '1365ac424857fea64faeed0771d72e11decb064e', // root
];

/** What a pinning verifier compares: [intermediate, root] — the leaf is never pinned. */
const FROZEN_PINNED_FINGERPRINTS = [
    '53d383651ef60004ae0fd68877e2edd1568446a5',
    '1365ac424857fea64faeed0771d72e11decb064e',
];

function frozenFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../../Fixtures/apple/'.$name);
}

/**
 * A committed certificate as one `x5c` header entry: standard (padded) base64
 * of the DER — RFC 7515 §4.1.6, NOT base64url.
 */
function frozenX5cEntry(string $name): string
{
    return (string) preg_replace('/-----.*?-----|\s+/', '', frozenFixture($name));
}

/** @return list<string> */
function frozenChain(): array
{
    return [
        frozenX5cEntry('leaf.pem'),
        frozenX5cEntry('intermediate.pem'),
        frozenX5cEntry('root.pem'),
    ];
}

/**
 * Sign an Apple-shaped ES256 notification with a committed private key.
 *
 * @param  array<string, mixed>  $claims
 * @param  list<string>  $x5c
 */
function frozenToken(array $claims, array $x5c, string $keyFixture = 'leaf-key.pem'): string
{
    $signer = new Es(EcKey::private(frozenFixture($keyFixture)));

    return (new Jws)->sign(['x5c' => $x5c], $claims, $signer);
}

/**
 * The production verifier with its anchors swapped for the fixture chain's, so
 * the positive trust path can be exercised (we cannot sign with Apple's key).
 * Everything else — CHAIN_LENGTH, the linkage check, the ES256 pin — is the
 * production code.
 */
function frozenVerifier(): JwsVerifier
{
    return new class extends JwsVerifier
    {
        /** @return list<string> */
        protected function fingerprints(): array
        {
            return FROZEN_PINNED_FINGERPRINTS;
        }
    };
}

it('pins apple published trust anchors', function (): void {
    $reflection = new ReflectionClass(JwsVerifier::class);

    // Apple's WWDR CA G6 (intermediate) and Apple Root CA G3, SHA-1, lower-case
    // hex, in [intermediate, root] order. These are the trust ruling.
    expect($reflection->getConstant('APPLE_CERTIFICATE_FINGERPRINTS'))->toBe([
        '0be38bfe21fd434d8cc51cbe0e2bc7758ddbf97b',
        'b52cb02fd567e0359fe8fa4d4c41037970fe01b0',
    ])
        ->and($reflection->getConstant('CHAIN_LENGTH'))->toBe(3);
});

it('pins the fingerprints of the committed chain', function (): void {
    foreach (['leaf.pem', 'intermediate.pem', 'root.pem'] as $index => $fixture) {
        $certificate = openssl_x509_read(frozenFixture($fixture));

        expect(openssl_x509_fingerprint($certificate))
            ->toBe(FROZEN_CHAIN_FINGERPRINTS[$index])
            ->toMatch('/^[0-9a-f]{40}$/');
    }
});

it('pins the pinned-fingerprint slice as [intermediate, root]', function (): void {
    // The verifier compares the chain MINUS the leaf, in leaf → root order. Any
    // replacement must reproduce exactly this list, in exactly this order.
    expect(array_slice(FROZEN_CHAIN_FINGERPRINTS, 1))->toBe(FROZEN_PINNED_FINGERPRINTS);
});

it('verifies a genuine notification against the pinned chain', function (): void {
    $compact = frozenToken(['notificationUUID' => 'frozen-1', 'notificationType' => 'DID_RENEW'], frozenChain());

    $manager = new JwsManager(frozenVerifier());
    $manager->verify($compact);

    expect($manager->parse($compact)->claims['notificationUUID'])->toBe('frozen-1');
});

it('rejects a notification whose payload was tampered with', function (): void {
    $compact = frozenToken(['notificationUUID' => 'frozen-1', 'transactionId' => 'tx-1'], frozenChain());

    [$header, , $signature] = explode('.', $compact);
    $forged = $header
        .'.'.Base64Url::encode('{"notificationUUID":"frozen-1","transactionId":"tx-EVIL"}')
        .'.'.$signature;

    (new JwsManager(frozenVerifier()))->verify($forged);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a well-formed chain from an unpinned ca', function (): void {
    $rogue = [
        frozenX5cEntry('rogue-leaf.pem'),
        frozenX5cEntry('rogue-intermediate.pem'),
        frozenX5cEntry('rogue-root.pem'),
    ];

    // Internally consistent and correctly signed — but not our anchors.
    (new JwsManager(frozenVerifier()))->verify(frozenToken(['notificationUUID' => 'n-1'], $rogue, 'rogue-leaf-key.pem'));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a rogue leaf under the pinned intermediate', function (): void {
    $x5c = [
        frozenX5cEntry('rogue-leaf.pem'),
        frozenX5cEntry('intermediate.pem'),
        frozenX5cEntry('root.pem'),
    ];

    // The pinned fingerprints still match — only the chain linkage catches this.
    (new JwsManager(frozenVerifier()))->verify(frozenToken(['notificationUUID' => 'n-1'], $x5c, 'rogue-leaf-key.pem'));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a token signed by a foreign key', function (): void {
    // Genuine, fully pinned chain — but the signature is not the leaf's.
    (new JwsManager(frozenVerifier()))->verify(frozenToken(['notificationUUID' => 'n-1'], frozenChain(), 'foreign-key.pem'));
})->throws(VerificationException::class, 'Payload verification failed.');

it('pins the stripe webhook signature vector', function (): void {
    // Untouched by the X.509 retrofit; frozen here so the swap is bisectable.
    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex(
        '1700000000.{"id":"evt_frozen","type":"payment_intent.succeeded"}',
        'whsec_frozen_test_secret',
    );

    expect($signature)->toBe('3c7a321d83e2a9e7e532e5d8e463f998a69ea16c8559157cf84735516f37b619');
});
