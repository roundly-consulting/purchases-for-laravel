<?php

declare(strict_types=1);

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;

/**
 * A throwaway three-certificate chain shaped like Apple's: a self-signed root,
 * an intermediate it signs, and a leaf the intermediate signs.
 */
final class TestChain
{
    public OpenSSLAsymmetricKey $leafKey;

    public OpenSSLCertificate $leaf;

    public OpenSSLCertificate $intermediate;

    public OpenSSLCertificate $root;

    public function __construct()
    {
        $rootKey = generateEcKey();
        $this->root = issueCertificate('Test Root', $rootKey);

        $intermediateKey = generateEcKey();
        $this->intermediate = issueCertificate('Test Intermediate', $intermediateKey, [$this->root, $rootKey]);

        $this->leafKey = generateEcKey();
        $this->leaf = issueCertificate('Test Leaf', $this->leafKey, [$this->intermediate, $intermediateKey]);
    }

    /** @return list<string> */
    public function x5c(): array
    {
        return [x5cEntry($this->leaf), x5cEntry($this->intermediate), x5cEntry($this->root)];
    }

    /** @return list<string> */
    public function fingerprints(): array
    {
        return [certificateFingerprint($this->intermediate), certificateFingerprint($this->root)];
    }

    /**
     * Mint a genuine App-Store-shaped ES256 notification signed by the leaf.
     *
     * @param  array<string, mixed>  $claims
     * @param  list<string>|null  $x5c
     */
    public function sign(array $claims, ?array $x5c = null, ?OpenSSLAsymmetricKey $key = null): string
    {
        $signer = new Es(EcKey::private(privatePem($key ?? $this->leafKey)));

        return (new Jws)->sign(['x5c' => $x5c ?? $this->x5c()], $claims, $signer);
    }
}

/**
 * The production verifier with its pins swapped for the test chain's, so the
 * signature path can be exercised against a chain we can actually issue.
 */
function verifierPinnedTo(TestChain $chain): JwsVerifier
{
    return new class($chain) extends JwsVerifier
    {
        public function __construct(private readonly TestChain $chain)
        {
            parent::__construct();
        }

        /** @return list<string> */
        protected function fingerprints(): array
        {
            return $this->chain->fingerprints();
        }
    };
}

it('verifies a genuine apple notification end to end', function (): void {
    $chain = new TestChain;
    $compact = $chain->sign(['notificationUUID' => 'n-1', 'notificationType' => 'DID_RENEW']);

    $manager = new JwsManager(verifierPinnedTo($chain));

    $manager->verify($compact);

    expect($manager->parse($compact)->claims['notificationUUID'])->toBe('n-1');
});

it('rejects a notification whose payload was tampered with', function (): void {
    $chain = new TestChain;
    $compact = $chain->sign(['notificationUUID' => 'n-1', 'transactionId' => 'tx-1']);

    // Swap the payload segment for a forged one, keeping header and signature.
    [$header, , $signature] = explode('.', $compact);
    $forged = $header
        .'.'.Base64Url::encode('{"notificationUUID":"n-1","transactionId":"tx-EVIL"}')
        .'.'.$signature;

    (new JwsManager(verifierPinnedTo($chain)))->verify($forged);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a chain that does not match the pinned fingerprints', function (): void {
    $chain = new TestChain;

    // The real verifier pins Apple's WWDR intermediate and G3 root — a
    // perfectly-formed chain from any other CA must not be trusted.
    (new JwsManager)->verify($chain->sign(['notificationUUID' => 'n-1']));
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a leaf that the pinned intermediate did not sign', function (): void {
    $chain = new TestChain;
    $rogue = new TestChain;

    // Pinned intermediate + root, but the leaf comes from a rogue CA: the
    // fingerprints still match, so only the chain-signature check catches this.
    $x5c = [x5cEntry($rogue->leaf), x5cEntry($chain->intermediate), x5cEntry($chain->root)];

    $compact = $chain->sign(['notificationUUID' => 'n-1'], $x5c, $rogue->leafKey);

    (new JwsManager(verifierPinnedTo($chain)))->verify($compact);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a token signed by a key other than the leaf certificate', function (): void {
    $chain = new TestChain;

    // Genuine, fully trusted chain — but the signature is from a foreign key.
    $compact = $chain->sign(['notificationUUID' => 'n-1'], key: generateEcKey());

    (new JwsManager(verifierPinnedTo($chain)))->verify($compact);
})->throws(VerificationException::class, 'Payload verification failed.');

it('rejects a chain that is not exactly three certificates', function (): void {
    $chain = new TestChain;
    $x5c = $chain->x5c();

    expect(verifierPinnedTo($chain)->verify(tokenWithChain([$x5c[0], $x5c[1]])))->toBeFalse()
        ->and(verifierPinnedTo($chain)->verify(tokenWithChain([])))->toBeFalse();
});

it('rejects an unreadable certificate in the header', function (): void {
    verifierPinnedTo(new TestChain)->verify(tokenWithChain(['not-a-cert', 'not-a-cert', 'not-a-cert']));
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
