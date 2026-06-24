<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Support\Base64Url;

/**
 * @param  array<string, mixed>  $header
 * @param  array<string, mixed>  $claims
 */
function makeJws(array $header, array $claims, string $signature = 'sig'): string
{
    return Base64Url::encode((string) json_encode($header))
        .'.'.Base64Url::encode((string) json_encode($claims))
        .'.'.Base64Url::encode($signature);
}

it('parses a well-formed ES256 token into header, claims and signature', function (): void {
    $token = (new JwsManager)->parse(makeJws(
        ['alg' => 'ES256', 'x5c' => ['a', 'b', 'c']],
        ['notificationUUID' => 'uuid-1', 'foo' => 'bar'],
        'raw-signature',
    ));

    expect($token)->toBeInstanceOf(DecodedToken::class)
        ->and($token->header['alg'])->toBe('ES256')
        ->and($token->claims)->toBe(['notificationUUID' => 'uuid-1', 'foo' => 'bar'])
        ->and($token->signature)->toBe('raw-signature')
        ->and($token->certificateChain())->toBe(['a', 'b', 'c']);
});

it('returns an empty chain when x5c is missing', function (): void {
    $token = (new JwsManager)->parse(makeJws(['alg' => 'ES256'], ['a' => 1]));

    expect($token->certificateChain())->toBe([]);
});

it('rejects a payload that does not have three segments', function (): void {
    (new JwsManager)->parse('only.two');
})->throws(VerificationException::class, 'Malformed JWS payload; expected three segments.');

it('rejects an unsupported algorithm', function (): void {
    (new JwsManager)->parse(makeJws(['alg' => 'RS256'], ['a' => 1]));
})->throws(VerificationException::class, 'Unsupported JWS algorithm; only ES256 is supported.');

it('rejects a header that is not a json object', function (): void {
    $payload = Base64Url::encode('"a string"')
        .'.'.Base64Url::encode('{}')
        .'.'.Base64Url::encode('sig');

    (new JwsManager)->parse($payload);
})->throws(VerificationException::class, 'Malformed JWS header; expected a JSON object.');

it('rejects a payload body that is not a json object', function (): void {
    $payload = Base64Url::encode('{"alg":"ES256"}')
        .'.'.Base64Url::encode('123')
        .'.'.Base64Url::encode('sig');

    (new JwsManager)->parse($payload);
})->throws(VerificationException::class, 'Malformed JWS payload; expected a JSON object.');

it('throws when verification fails', function (): void {
    $manager = new JwsManager(new class extends JwsVerifier
    {
        public function verify(DecodedToken $token): bool
        {
            return false;
        }
    });

    $manager->verify(makeJws(['alg' => 'ES256'], ['a' => 1]));
})->throws(VerificationException::class, 'Payload verification failed.');

it('passes when the verifier accepts the token', function (): void {
    $manager = new JwsManager(new class extends JwsVerifier
    {
        public function verify(DecodedToken $token): bool
        {
            return true;
        }
    });

    $manager->verify(makeJws(['alg' => 'ES256'], ['a' => 1]));

    expect(true)->toBeTrue();
});
