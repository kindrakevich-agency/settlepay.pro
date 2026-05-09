<?php

namespace App\Service\Auth;

/**
 * Centralizes how we generate + verify single-use tokens for email
 * verification and password reset.
 *
 * The plaintext token is mailed to the user. The DB only stores the
 * SHA-256 hash. To verify, we hash whatever the user presents and look
 * for that hash in the DB. A leaked DB dump cannot be replayed because
 * SHA-256 is one-way for a 32-byte high-entropy input.
 */
final class TokenGenerator
{
    /** Returns the URL-safe plaintext token (never persisted). */
    public function generatePlain(): string
    {
        // 32 bytes from CSPRNG → 43 base64url chars. More than enough entropy.
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Returns the SHA-256 hex of a plaintext token. Stored in DB. */
    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Constant-time comparison — a defence against timing attacks even
     * though hash lookup against the DB index already obviates them in
     * practice.
     */
    public function verify(string $plain, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($plain));
    }
}
