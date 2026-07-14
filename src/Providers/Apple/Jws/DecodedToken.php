<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

/**
 * A parsed (but not yet verified) JWS compact token.
 *
 * Holds the decoded protected header and payload claims plus the original
 * compact serialization. The header is what selects the signing certificate
 * (`x5c`); the compact form is what the signature is verified over, so the
 * bytes that are checked are always the bytes that arrived.
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
        public string $compact,
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
