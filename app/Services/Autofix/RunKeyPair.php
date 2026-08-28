<?php

namespace App\Services\Autofix;

/**
 * The Ed25519 keypair for one run.
 *
 * This is what replaced the shared secret. Bilis mints a pair per job, injects
 * the private half into that one run, and keeps the public half on the job row.
 * Everything the run posts back is verified against that column and nothing
 * else — so a key recovered from a compromised container authenticates exactly
 * one job, which is already over, rather than every job in both directions
 * forever.
 *
 * The private half is deliberately not stored anywhere. It exists inside the
 * spec that starts the run and in this object for as long as the dispatch takes.
 */
final readonly class RunKeyPair
{
    private function __construct(
        /** The 32-byte seed, base64. This goes into the run and nowhere else. */
        public string $signingKey,
        /** The 32-byte public key, base64. This is stored on the job row. */
        public string $publicKey,
    ) {}

    /**
     * Mint a fresh pair.
     *
     * libsodium ships with PHP, so this takes no new dependency — the same
     * instinct that keeps a hand-rolled RS256 in `GitHubAppTokenService` and a
     * hand-rolled EdDSA JWT in `StreamTokenIssuer`.
     */
    public static function mint(): self
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return new self(
            base64_encode($seed),
            base64_encode(sodium_crypto_sign_publickey($keypair)),
        );
    }
}
