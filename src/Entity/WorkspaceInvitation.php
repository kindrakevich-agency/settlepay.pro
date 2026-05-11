<?php

namespace App\Entity;

use App\Repository\WorkspaceInvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pending email invitation. The recipient receives a link with the
 * raw token. On accept, we look the row up by token, validate
 * not-expired + not-revoked + email match (if logged in), then
 * create a workspace_members row + stamp accepted_at.
 */
#[ORM\Entity(repositoryClass: WorkspaceInvitationRepository::class)]
#[ORM\Table(name: 'workspace_invitations')]
class WorkspaceInvitation
{
    public const ROLE_MEMBER = WorkspaceMember::ROLE_MEMBER;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 16, options: ['default' => self::ROLE_MEMBER])]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invited_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $invitedBy;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $expiresAt;

    #[ORM\Column(name: 'accepted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $acceptedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+14 days');
        $this->token     = bin2hex(random_bytes(24)); // 48-char hex
    }

    public function getId(): ?string                             { return $this->id; }
    public function getWorkspace(): Workspace                    { return $this->workspace; }
    public function setWorkspace(Workspace $w): self             { $this->workspace = $w; return $this; }
    public function getEmail(): string                           { return $this->email; }
    public function setEmail(string $e): self                    { $this->email = strtolower(trim($e)); return $this; }
    public function getRole(): string                            { return $this->role; }
    public function setRole(string $r): self                     { $this->role = $r; return $this; }
    public function getToken(): string                           { return $this->token; }
    public function getInvitedBy(): User                         { return $this->invitedBy; }
    public function setInvitedBy(User $u): self                  { $this->invitedBy = $u; return $this; }
    public function getExpiresAt(): \DateTimeInterface           { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeInterface $t): self    { $this->expiresAt = $t; return $this; }
    public function getAcceptedAt(): ?\DateTimeInterface         { return $this->acceptedAt; }
    public function markAccepted(): self                         { $this->acceptedAt = new \DateTimeImmutable(); return $this; }
    public function getRevokedAt(): ?\DateTimeInterface          { return $this->revokedAt; }
    public function markRevoked(): self                          { $this->revokedAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeInterface           { return $this->createdAt; }

    public function isPending(): bool
    {
        return $this->acceptedAt === null
            && $this->revokedAt === null
            && $this->expiresAt > new \DateTimeImmutable();
    }
}
