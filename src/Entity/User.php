<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(type: Types::GUID, length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $emailVerifiedAt = null;

    /** SHA-256 hex of the plaintext token mailed to the user. */
    #[ORM\Column(name: 'email_verification_token', length: 64, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(name: 'email_verification_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $emailVerificationExpiresAt = null;

    /** Stable Google user id (the `sub` claim). Set on first GIS sign-in; never changes. */
    #[ORM\Column(name: 'google_sub', length: 64, nullable: true, unique: true)]
    private ?string $googleSub = null;

    #[ORM\Column(name: 'password_reset_token', length: 64, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(name: 'password_reset_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $passwordResetExpiresAt = null;

    #[ORM\Column(name: 'last_login_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLoginAt = null;

    #[ORM\Column(name: 'display_name', length: 120, nullable: true)]
    private ?string $displayName = null;

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

    /** Lower-case EIP-55 wallet address — receives all stablecoin payments. */
    #[ORM\Column(name: 'payout_address', length: 64)]
    private string $payoutAddress;

    #[ORM\Column(name: 'payout_chain_id', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $payoutChainId;

    #[ORM\Column(name: 'payout_token', length: 20, options: ['default' => 'USDC'])]
    private string $payoutToken = 'USDC';

    /**
     * Pro-only custom branding. Persisted as relative path under public/uploads/branding/.
     * Display via assetUrl(brandLogoPath). Cleared (NULL) on user removal of logo.
     */
    #[ORM\Column(name: 'brand_logo_path', length: 255, nullable: true)]
    private ?string $brandLogoPath = null;

    /** Pro-only brand accent color, 7-char hex (#rrggbb), validated on save. */
    #[ORM\Column(name: 'brand_color', length: 7, nullable: true)]
    private ?string $brandColor = null;

    #[ORM\Column(length: 20, options: ['default' => 'free'])]
    private string $plan = 'free';

    /**
     * For Pro plans: when the current billing period ends.
     *   NULL + plan='pro'  → lifetime Pro (no renewal needed)
     *   set + plan='pro'   → renewable monthly Pro
     *   set + plan='free'  → never (free plan ignores this)
     */
    #[ORM\Column(name: 'plan_renews_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $planRenewsAt = null;

    /** When the user clicked "Cancel" — they keep access until plan_renews_at then drop to free. */
    #[ORM\Column(name: 'plan_canceled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $planCanceledAt = null;

    /** Accumulated unpaid per-invoice fees (1% Free, 0.5% Pro). Cleared when user pays via fee_settlement intent. */
    #[ORM\Column(name: 'fees_owed_cents', type: Types::BIGINT, options: ['unsigned' => true, 'default' => 0])]
    private string $feesOwedCents = '0';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    /** @var Collection<int, Invoice> */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'user')]
    private Collection $invoices;

    /** @var Collection<int, Workspace> */
    #[ORM\OneToMany(targetEntity: Workspace::class, mappedBy: 'owner')]
    private Collection $ownedWorkspaces;

    public function __construct()
    {
        $this->uuid            = Uuid::v7()->toRfc4122();
        $this->createdAt       = new \DateTimeImmutable();
        $this->updatedAt       = new \DateTimeImmutable();
        $this->invoices        = new ArrayCollection();
        $this->ownedWorkspaces = new ArrayCollection();
    }

    /** @return Collection<int, Workspace> */
    public function getOwnedWorkspaces(): Collection             { return $this->ownedWorkspaces; }

    public function getId(): ?string                          { return $this->id; }
    public function getUuid(): string                         { return $this->uuid; }
    public function getEmail(): string                        { return $this->email; }
    public function setEmail(string $email): self             { $this->email = $email; return $this; }
    public function getPasswordHash(): string                 { return $this->passwordHash; }
    public function setPasswordHash(string $h): self          { $this->passwordHash = $h; return $this; }
    public function getEmailVerifiedAt(): ?\DateTimeInterface { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeInterface $d): self { $this->emailVerifiedAt = $d; return $this; }
    public function isEmailVerified(): bool { return $this->emailVerifiedAt !== null; }

    public function getEmailVerificationToken(): ?string { return $this->emailVerificationToken; }
    public function setEmailVerificationToken(?string $t): self { $this->emailVerificationToken = $t; return $this; }
    public function getEmailVerificationExpiresAt(): ?\DateTimeInterface { return $this->emailVerificationExpiresAt; }
    public function setEmailVerificationExpiresAt(?\DateTimeInterface $d): self { $this->emailVerificationExpiresAt = $d; return $this; }
    public function getGoogleSub(): ?string                   { return $this->googleSub; }
    public function setGoogleSub(?string $s): self            { $this->googleSub = $s; return $this; }

    public function getPasswordResetToken(): ?string { return $this->passwordResetToken; }
    public function setPasswordResetToken(?string $t): self { $this->passwordResetToken = $t; return $this; }
    public function getPasswordResetExpiresAt(): ?\DateTimeInterface { return $this->passwordResetExpiresAt; }
    public function setPasswordResetExpiresAt(?\DateTimeInterface $d): self { $this->passwordResetExpiresAt = $d; return $this; }

    public function getLastLoginAt(): ?\DateTimeInterface { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeInterface $d): self { $this->lastLoginAt = $d; return $this; }
    public function getDisplayName(): ?string                 { return $this->displayName; }
    public function setDisplayName(?string $n): self          { $this->displayName = $n; return $this; }
    public function getBusinessName(): ?string                { return $this->businessName; }
    public function setBusinessName(?string $n): self         { $this->businessName = $n; return $this; }
    public function getBusinessAddress(): ?string             { return $this->businessAddress; }
    public function setBusinessAddress(?string $a): self      { $this->businessAddress = $a; return $this; }
    public function getTaxId(): ?string                       { return $this->taxId; }
    public function setTaxId(?string $id): self               { $this->taxId = $id; return $this; }
    public function getDefaultCurrency(): string              { return $this->defaultCurrency; }
    public function setDefaultCurrency(string $c): self       { $this->defaultCurrency = $c; return $this; }
    public function getDefaultLocale(): string                { return $this->defaultLocale; }
    public function setDefaultLocale(string $l): self         { $this->defaultLocale = $l; return $this; }
    public function getPayoutAddress(): string                { return $this->payoutAddress; }
    public function setPayoutAddress(string $a): self         { $this->payoutAddress = strtolower($a); return $this; }
    public function getPayoutChainId(): int                   { return $this->payoutChainId; }
    public function setPayoutChainId(int $id): self           { $this->payoutChainId = $id; return $this; }
    public function getPayoutToken(): string                  { return $this->payoutToken; }
    public function setPayoutToken(string $t): self           { $this->payoutToken = $t; return $this; }
    public function getBrandLogoPath(): ?string               { return $this->brandLogoPath; }
    public function setBrandLogoPath(?string $p): self        { $this->brandLogoPath = $p; return $this; }
    public function getBrandColor(): ?string                  { return $this->brandColor; }
    public function setBrandColor(?string $c): self           { $this->brandColor = $c; return $this; }
    public function hasCustomBranding(): bool                 { return $this->brandLogoPath !== null || $this->brandColor !== null; }
    public function getPlan(): string                         { return $this->plan; }
    public function setPlan(string $p): self                  { $this->plan = $p; return $this; }
    public function getPlanRenewsAt(): ?\DateTimeInterface    { return $this->planRenewsAt; }
    public function setPlanRenewsAt(?\DateTimeInterface $t): self { $this->planRenewsAt = $t; return $this; }
    public function getPlanCanceledAt(): ?\DateTimeInterface  { return $this->planCanceledAt; }
    public function setPlanCanceledAt(?\DateTimeInterface $t): self { $this->planCanceledAt = $t; return $this; }
    public function getFeesOwedCents(): int                   { return (int) $this->feesOwedCents; }
    public function setFeesOwedCents(int $c): self            { $this->feesOwedCents = (string) max(0, $c); return $this; }
    public function addFeesOwedCents(int $delta): self        { $this->feesOwedCents = (string) max(0, (int)$this->feesOwedCents + $delta); return $this; }
    public function isPro(): bool                             { return $this->plan === 'pro' && ($this->planRenewsAt === null || $this->planRenewsAt > new \DateTimeImmutable()); }
    public function isProLifetime(): bool                     { return $this->plan === 'pro' && $this->planRenewsAt === null; }
    public function feeRateBps(): int                         { return $this->isPro() ? 50 : 100; } // 0.5% pro, 1% free
    public function getCreatedAt(): \DateTimeInterface        { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface        { return $this->updatedAt; }
    public function touch(): self                             { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, Invoice> */
    public function getInvoices(): Collection { return $this->invoices; }

    // ─── Symfony Security ────────────────────────────────────
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array           { return ['ROLE_USER']; }
    public function getPassword(): ?string      { return $this->passwordHash; }
    public function eraseCredentials(): void    { /* no-op — we never store plaintext */ }
}
