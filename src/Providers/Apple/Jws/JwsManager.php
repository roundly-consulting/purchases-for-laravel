<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Codec\InvalidEncodingException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * Parses Apple App Store Server JWS (signed) payloads and hands them to the
 * verifier.
 *
 * Parsing reads only what is needed to *find* the signing certificate: the
 * protected header (for `x5c`) and the claims. None of it is trusted until
 * {@see JwsVerifier} has validated Apple's certificate chain and the ES256
 * signature over the untouched compact token.
 */
class JwsManager
{
    private const SUPPORTED_ALGORITHM = 'ES256';

    public function __construct(
        private readonly JwsVerifier $verifier = new JwsVerifier,
    ) {}

    /**
     * Decode a JWS compact serialization into its header and claims.
     */
    public function parse(string $payload): DecodedToken
    {
        $segments = explode('.', $payload);

        if (count($segments) !== 3) {
            throw VerificationException::because('Malformed JWS payload; expected three segments.');
        }

        [$encodedHeader, $encodedClaims] = $segments;

        $header = $this->decodeJsonSegment($encodedHeader, 'header');
        $claims = $this->decodeJsonSegment($encodedClaims, 'payload');

        $algorithm = $header['alg'] ?? null;

        if ($algorithm !== self::SUPPORTED_ALGORITHM) {
            throw VerificationException::because('Unsupported JWS algorithm; only ES256 is supported.');
        }

        return new DecodedToken(
            header: $header,
            claims: $claims,
            compact: $payload,
        );
    }

    /**
     * Verify a JWS payload's signature against Apple's certificate chain.
     */
    public function verify(string|DecodedToken $payload): void
    {
        $token = is_string($payload) ? $this->parse($payload) : $payload;

        if (! $this->verifier->verify($token)) {
            throw VerificationException::because('Payload verification failed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonSegment(string $segment, string $label): array
    {
        try {
            $json = Base64Url::decode($segment);
        } catch (InvalidEncodingException $e) {
            throw new VerificationException('Invalid base64url segment.', previous: $e);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw VerificationException::because("Malformed JWS {$label}; expected a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
