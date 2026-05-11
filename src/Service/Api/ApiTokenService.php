<?php

namespace App\Service\Api;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mint, hash, and validate personal access tokens.
 *
 * Token layout (length = 7 + 43 = 50 chars):
 *
 *     sk_pro_<43 base64url chars>
 *     │      └── 32 random bytes of entropy
 *     └── fixed brand prefix so leaked tokens are grep-able in code
 *
 * Storage:
 *   - token_prefix: first 16 chars of the plaintext (e.g. "sk_pro_abc12345").
 *     Indexed for O(1) lookup before we run Argon2id verify().
 *   - token_hash: PASSWORD_ARGON2ID of the full plaintext. We never persist
 *     the plaintext anywhere — the user sees it once at creation.
 */
class ApiTokenService
{
    public const TOKEN_PREFIX = 'sk_pro_';
    public const PREFIX_LENGTH = 16; // chars stored as the visible prefix

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Generate a new token scoped to a workspace, created by a user.
     *
     * @return array{plaintext: string, token: ApiToken}
     */
    public function generate(Workspace $workspace, User $createdBy, string $name, array $scopes = ['read', 'write']): array
    {
        $plaintext = $this->makePlaintext();

        $token = (new ApiToken())
            ->setUser($createdBy)
            ->setWorkspace($workspace)
            ->setName(trim($name) !== '' ? trim($name) : 'API token')
            ->setTokenPrefix(substr($plaintext, 0, self::PREFIX_LENGTH))
            ->setTokenHash(password_hash($plaintext, PASSWORD_ARGON2ID))
            ->setScopes($scopes);

        $this->em->persist($token);
        $this->em->flush();

        return ['plaintext' => $plaintext, 'token' => $token];
    }

    /**
     * Verify an incoming Bearer token against the database. Returns the
     * owning ApiToken (and updates last_used_at) on success, or null.
     */
    public function verify(string $plaintext): ?ApiToken
    {
        if (!str_starts_with($plaintext, self::TOKEN_PREFIX)) {
            return null;
        }
        $prefix = substr($plaintext, 0, self::PREFIX_LENGTH);
        if (strlen($prefix) !== self::PREFIX_LENGTH) {
            return null;
        }

        $candidates = $this->tokens->findActiveByPrefix($prefix);
        foreach ($candidates as $cand) {
            if (password_verify($plaintext, $cand->getTokenHash())) {
                $cand->setLastUsedAt(new \DateTimeImmutable());
                $this->em->flush();
                return $cand;
            }
        }
        return null;
    }

    public function revoke(ApiToken $token): void
    {
        if ($token->getRevokedAt() === null) {
            $token->setRevokedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function makePlaintext(): string
    {
        // 32 random bytes → 43 base64url chars (no padding).
        $random = random_bytes(32);
        $b64 = rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
        return self::TOKEN_PREFIX . $b64;
    }
}
