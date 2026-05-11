<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personal access token for the Settlepay API.
 *
 * Plaintext format: `sk_pro_<43 base64url chars>` (32 random bytes,
 * ~256 bits of entropy). The user sees it ONCE at creation. Only the
 * Argon2id hash is stored — verifying a request token uses
 * password_verify() on the hash.
 *
 * The 16-char `token_prefix` is the visible portion (`sk_pro_abc123`)
 * shown in the settings UI + used as the database lookup hint so
 * Argon2id verification only runs once per request.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    /** Who minted the token. Ownership/scoping uses `workspace`. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Workspace $workspace = null;

    /** Human-readable label set by the user, e.g. "Zapier integration". */
    #[ORM\Column(length: 80)]
    private string $name;

    /** First 16 chars of the plaintext (e.g. "sk_pro_abc12345"). Indexed for fast lookup. */
    #[ORM\Column(name: 'token_prefix', length: 16)]
    private string $tokenPrefix;

    /** Argon2id hash of the full plaintext. */
    #[ORM\Column(name: 'token_hash', length: 255)]
    private string $tokenHash;

    /** Scope strings — minimal set in v1: ['read', 'write']. */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = ['read', 'write'];

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastUsedAt = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string                              { return $this->id; }
    public function getUser(): User                               { return $this->user; }
    public function setUser(User $u): self                        { $this->user = $u; return $this; }
    public function getWorkspace(): ?Workspace                    { return $this->workspace; }
    public function setWorkspace(?Workspace $w): self             { $this->workspace = $w; return $this; }
    public function getName(): string                             { return $this->name; }
    public function setName(string $n): self                      { $this->name = $n; return $this; }
    public function getTokenPrefix(): string                      { return $this->tokenPrefix; }
    public function setTokenPrefix(string $p): self               { $this->tokenPrefix = $p; return $this; }
    public function getTokenHash(): string                        { return $this->tokenHash; }
    public function setTokenHash(string $h): self                 { $this->tokenHash = $h; return $this; }
    public function getScopes(): array                            { return $this->scopes; }
    public function setScopes(array $s): self                     { $this->scopes = $s; return $this; }
    public function getLastUsedAt(): ?\DateTimeInterface          { return $this->lastUsedAt; }
    public function setLastUsedAt(?\DateTimeInterface $t): self   { $this->lastUsedAt = $t; return $this; }
    public function getExpiresAt(): ?\DateTimeInterface           { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeInterface $t): self    { $this->expiresAt = $t; return $this; }
    public function getRevokedAt(): ?\DateTimeInterface           { return $this->revokedAt; }
    public function setRevokedAt(?\DateTimeInterface $t): self    { $this->revokedAt = $t; return $this; }
    public function getCreatedAt(): \DateTimeInterface            { return $this->createdAt; }

    public function isActive(): bool
    {
        if ($this->revokedAt !== null) return false;
        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) return false;
        return true;
    }
}
