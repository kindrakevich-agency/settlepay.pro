<?php

namespace App\Entity;

use App\Repository\WorkspaceMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per (workspace, user) link. Role gates write actions
 * inside the workspace.
 *
 *   owner  : everything (billing, invite, remove, branding)
 *   member : create/send/void their own invoices, view all workspace
 *            invoices/payments. Cannot change billing or branding.
 */
#[ORM\Entity(repositoryClass: WorkspaceMemberRepository::class)]
#[ORM\Table(name: 'workspace_members')]
class WorkspaceMember
{
    public const ROLE_OWNER  = 'owner';
    public const ROLE_MEMBER = 'member';

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class, inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Workspace $workspace;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, options: ['default' => self::ROLE_MEMBER])]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getWorkspace(): Workspace                   { return $this->workspace; }
    public function setWorkspace(Workspace $w): self            { $this->workspace = $w; return $this; }
    public function getUser(): User                             { return $this->user; }
    public function setUser(User $u): self                      { $this->user = $u; return $this; }
    public function getRole(): string                           { return $this->role; }
    public function setRole(string $r): self                    { $this->role = $r; return $this; }
    public function isOwner(): bool                             { return $this->role === self::ROLE_OWNER; }
    public function getJoinedAt(): \DateTimeInterface           { return $this->joinedAt; }
}
