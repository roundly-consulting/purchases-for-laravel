<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\Base64Url;

/**
 * Parses and verifies Apple App Store Server JWS (signed) payloads natively,
 * using ext-openssl only — no third-party JWT dependency.
 */
class JwsManager
{
    private const SUPPORTED_ALGORITHM = 'ES256';

    public function __construct(
        private readonly JwsVerifier $verifier = new JwsVerifier,
    ) {}

    /**
     * Decode a JWS compact serialization into its header, claims, and signature.
     */
    public function parse(string $payload): DecodedToken
    {
        $segments = explode('.', $payload);

        if (count($segments) !== 3) {
            throw VerificationException::because('Malformed JWS payload; expected three segments.');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;

        $header = $this->decodeJsonSegment($encodedHeader, 'header');
        $claims = $this->decodeJsonSegment($encodedClaims, 'payload');

        $algorithm = $header['alg'] ?? null;

        if ($algorithm !== self::SUPPORTED_ALGORITHM) {
            throw VerificationException::because('Unsupported JWS algorithm; only ES256 is supported.');
        }

        return new DecodedToken(
            header: $header,
            claims: $claims,
            signingInput: $encodedHeader.'.'.$encodedClaims,
            signature: Base64Url::decode($encodedSignature),
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
        $decoded = json_decode(Base64Url::decode($segment), true);

        if (! is_array($decoded)) {
            throw VerificationException::because("Malformed JWS {$label}; expected a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
