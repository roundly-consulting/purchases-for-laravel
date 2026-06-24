<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

/**
 * A parsed (but not necessarily verified) JWS compact token.
 *
 * Holds the decoded protected header and payload claims along with the exact
 * signing input and raw signature bytes needed to verify the ES256 signature.
 */
final readonly class DecodedToken
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public array $header,
        public array $claims,
        public string $signingInput,
        public string $signature,
    ) {}

    /**
     * The `x5c` certificate chain from the protected header (base64 DER entries).
     *
     * @return list<string>
     */
    public function certificateChain(): array
    {
        $x5c = $this->header['x5c'] ?? [];

        return is_array($x5c) ? array_values(array_map(strval(...), $x5c)) : [];
    }
}
