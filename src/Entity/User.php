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

    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $emailVerifiedAt = null;

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

    #[ORM\Column(length: 20, options: ['default' => 'free'])]
    private string $plan = 'free';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    /** @var Collection<int, Invoice> */
    #[ORM\OneToMany(targetEntity: Invoice::class, mappedBy: 'user')]
    private Collection $invoices;

    public function __construct()
    {
        $this->uuid       = Uuid::v7()->toRfc4122();
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = new \DateTimeImmutable();
        $this->invoices   = new ArrayCollection();
    }

    public function getId(): ?string                          { return $this->id; }
    public function getUuid(): string                         { return $this->uuid; }
    public function getEmail(): string                        { return $this->email; }
    public function setEmail(string $email): self             { $this->email = $email; return $this; }
    public function getPasswordHash(): string                 { return $this->passwordHash; }
    public function setPasswordHash(string $h): self          { $this->passwordHash = $h; return $this; }
    public function getEmailVerifiedAt(): ?\DateTimeInterface { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeInterface $d): self { $this->emailVerifiedAt = $d; return $this; }
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
    public function getPlan(): string                         { return $this->plan; }
    public function setPlan(string $p): self                  { $this->plan = $p; return $this; }
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
