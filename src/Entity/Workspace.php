<?php

namespace App\Entity;

use App\Repository\WorkspaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A workspace is the "business" — the unit that owns invoices,
 * billing state, and branding. Solo freelancers get exactly one
 * workspace where they are the sole Owner. Agency-tier accounts
 * can invite Members (up to seat_limit).
 *
 * Phase 1: workspace fields are seeded from the original user row.
 * Per-user payout/billing/branding columns on `users` remain in
 * place during Phase 1 — Phase 2 will remove them.
 */
#[ORM\Entity(repositoryClass: WorkspaceRepository::class)]
#[ORM\Table(name: 'workspaces')]
class Workspace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 20, options: ['default' => 'free'])]
    private string $plan = 'free';

    #[ORM\Column(name: 'plan_renews_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $planRenewsAt = null;

    #[ORM\Column(name: 'plan_canceled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $planCanceledAt = null;

    #[ORM\Column(name: 'fees_owed_cents', type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
    private string $feesOwedCents = '0';

    #[ORM\Column(name: 'business_name', length: 180, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(name: 'business_address', type: Types::TEXT, nullable: true)]
    private ?string $businessAddress = null;

    #[ORM\Column(name: 'tax_id', length: 60, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(name: 'default_currency', length: 3, options: ['default' => 'USD'])]
    private string $defaultCurrency = 'USD';

    #[ORM\Column(name: 'default_locale', length: 5, options: ['default' => 'en'])]
    private string $defaultLocale = 'en';

    #[ORM\Column(name: 'payout_address', length: 64)]
    private string $payoutAddress = '0x0000000000000000000000000000000000000000';

    #[ORM\Column(name: 'payout_chain_id', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $payoutChainId = 8453;

    #[ORM\Column(name: 'payout_token', length: 20, options: ['default' => 'USDC'])]
    private string $payoutToken = 'USDC';

    #[ORM\Column(name: 'brand_logo_path', length: 255, nullable: true)]
    private ?string $brandLogoPath = null;

    #[ORM\Column(name: 'brand_color', length: 7, nullable: true)]
    private ?string $brandColor = null;

    /** Plan-driven cap; 1 for solo, 5 for Agency. */
    #[ORM\Column(name: 'seat_limit', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 1])]
    private int $seatLimit = 1;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $owner;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    /** @var Collection<int, WorkspaceMember> */
    #[ORM\OneToMany(targetEntity: WorkspaceMember::class, mappedBy: 'workspace', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    public function __construct()
    {
        $this->uuid      = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->members   = new ArrayCollection();
    }

    public function getId(): ?string                            { return $this->id; }
    public function getUuid(): string                           { return $this->uuid; }
    public function getName(): string                           { return $this->name; }
    public function setName(string $n): self                    { $this->name = $n; return $this; }
    public function getPlan(): string                           { return $this->plan; }
    public function setPlan(string $p): self                    { $this->plan = $p; return $this; }
    public function getPlanRenewsAt(): ?\DateTimeInterface      { return $this->planRenewsAt; }
    public function setPlanRenewsAt(?\DateTimeInterface $t): self { $this->planRenewsAt = $t; return $this; }
    public function getPlanCanceledAt(): ?\DateTimeInterface    { return $this->planCanceledAt; }
    public function setPlanCanceledAt(?\DateTimeInterface $t): self { $this->planCanceledAt = $t; return $this; }
    public function getFeesOwedCents(): int                     { return (int) $this->feesOwedCents; }
    public function setFeesOwedCents(int $c): self              { $this->feesOwedCents = (string) max(0, $c); return $this; }
    public function addFeesOwedCents(int $delta): self          { $this->feesOwedCents = (string) max(0, (int) $this->feesOwedCents + $delta); return $this; }
    public function getBusinessName(): ?string                  { return $this->businessName; }
    public function setBusinessName(?string $n): self           { $this->businessName = $n; return $this; }
    public function getBusinessAddress(): ?string               { return $this->businessAddress; }
    public function setBusinessAddress(?string $a): self        { $this->businessAddress = $a; return $this; }
    public function getTaxId(): ?string                         { return $this->taxId; }
    public function setTaxId(?string $id): self                 { $this->taxId = $id; return $this; }
    public function getDefaultCurrency(): string                { return $this->defaultCurrency; }
    public function setDefaultCurrency(string $c): self         { $this->defaultCurrency = $c; return $this; }
    public function getDefaultLocale(): string                  { return $this->defaultLocale; }
    public function setDefaultLocale(string $l): self           { $this->defaultLocale = $l; return $this; }
    public function getPayoutAddress(): string                  { return $this->payoutAddress; }
    public function setPayoutAddress(string $a): self           { $this->payoutAddress = strtolower($a); return $this; }
    public function getPayoutChainId(): int                     { return $this->payoutChainId; }
    public function setPayoutChainId(int $id): self             { $this->payoutChainId = $id; return $this; }
    public function getPayoutToken(): string                    { return $this->payoutToken; }
    public function setPayoutToken(string $t): self             { $this->payoutToken = $t; return $this; }
    public function getBrandLogoPath(): ?string                 { return $this->brandLogoPath; }
    public function setBrandLogoPath(?string $p): self          { $this->brandLogoPath = $p; return $this; }
    public function getBrandColor(): ?string                    { return $this->brandColor; }
    public function setBrandColor(?string $c): self             { $this->brandColor = $c; return $this; }
    public function getSeatLimit(): int                         { return $this->seatLimit; }
    public function setSeatLimit(int $n): self                  { $this->seatLimit = max(1, $n); return $this; }
    public function getOwner(): User                            { return $this->owner; }
    public function setOwner(User $u): self                     { $this->owner = $u; return $this; }
    public function getCreatedAt(): \DateTimeInterface          { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface          { return $this->updatedAt; }
    public function touch(): self                               { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, WorkspaceMember> */
    public function getMembers(): Collection                    { return $this->members; }

    public function isPro(): bool
    {
        return in_array($this->plan, ['pro', 'agency'], true)
            && ($this->planRenewsAt === null || $this->planRenewsAt > new \DateTimeImmutable());
    }
    public function isAgency(): bool       { return $this->plan === 'agency' && $this->isPro(); }
    public function isProLifetime(): bool  { return $this->plan === 'pro' && $this->planRenewsAt === null; }
    public function feeRateBps(): int      { return $this->isPro() ? 50 : 100; }
}
