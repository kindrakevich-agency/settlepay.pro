<?php

namespace App\Entity;

use App\Enum\BillingIntentKind;
use App\Enum\BillingIntentStatus;
use App\Repository\BillingIntentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A payment request Settlepay generates so the freelancer can pay for
 * something in crypto: Pro subscription renewal, lifetime upgrade, or
 * settling accumulated per-invoice fees.
 *
 * Lifecycle: pending → paid OR expired (TTL 1h by default).
 *
 * On the public /billing/pay/{uuid} page the freelancer connects their
 * wallet and sends amount_cents worth of USDC to recipient_address (the
 * platform wallet). The same listener daemon that watches invoice
 * recipients also watches the platform wallet — when it sees a matching
 * Transfer, BillingMatcher flips this intent to Paid and credits the
 * user's account.
 */
#[ORM\Entity(repositoryClass: BillingIntentRepository::class)]
#[ORM\Table(name: 'billing_intents')]
class BillingIntent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    /** Who clicked Upgrade — kept for audit. Ownership is on `workspace`. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Workspace $workspace = null;

    /** Public-facing identifier — the /billing/pay/{uuid} URL uses this. */
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(type: Types::STRING, length: 40, enumType: BillingIntentKind::class)]
    private BillingIntentKind $kind;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: BillingIntentStatus::class, options: ['default' => 'pending'])]
    private BillingIntentStatus $status = BillingIntentStatus::Pending;

    /** Amount expected, in integer cents. Stablecoin = 1:1 with USD. */
    #[ORM\Column(name: 'amount_cents', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $amountCents;

    #[ORM\Column(length: 3, options: ['default' => 'USD'])]
    private string $currency = 'USD';

    /** @var int[] */
    #[ORM\Column(name: 'accepted_chains', type: Types::JSON)]
    private array $acceptedChains = [];

    /** @var string[] */
    #[ORM\Column(name: 'accepted_tokens', type: Types::JSON)]
    private array $acceptedTokens = [];

    /** Lowercase platform wallet address (snapshot at creation time). */
    #[ORM\Column(name: 'recipient_address', length: 64)]
    private string $recipientAddress;

    /** Optional: if set, listener requires the on-chain `from` to match this. */
    #[ORM\Column(name: 'expected_payer_address', length: 64, nullable: true)]
    private ?string $expectedPayerAddress = null;

    /** Frontend-reported tx hash (fast-path). */
    #[ORM\Column(name: 'claimed_tx_hash', length: 80, nullable: true)]
    private ?string $claimedTxHash = null;

    #[ORM\Column(name: 'claimed_chain_id', type: Types::INTEGER, options: ['unsigned' => true], nullable: true)]
    private ?int $claimedChainId = null;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $claimedAt = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\ManyToOne(targetEntity: FeePayment::class)]
    #[ORM\JoinColumn(name: 'paid_fee_payment_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?FeePayment $paidFeePayment = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $expiresAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->uuid      = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+1 hour');
    }

    public function getId(): ?string                              { return $this->id; }
    public function getUuid(): string                             { return $this->uuid; }
    public function getUser(): User                               { return $this->user; }
    public function setUser(User $user): self                     { $this->user = $user; return $this; }
    public function getWorkspace(): ?Workspace                    { return $this->workspace; }
    public function setWorkspace(?Workspace $w): self             { $this->workspace = $w; return $this; }
    public function getKind(): BillingIntentKind                  { return $this->kind; }
    public function setKind(BillingIntentKind $k): self           { $this->kind = $k; return $this; }
    public function getStatus(): BillingIntentStatus              { return $this->status; }
    public function setStatus(BillingIntentStatus $s): self       { $this->status = $s; return $this; }
    public function getAmountCents(): int                         { return (int) $this->amountCents; }
    public function setAmountCents(int $c): self                  { $this->amountCents = (string) $c; return $this; }
    public function getCurrency(): string                         { return $this->currency; }
    public function setCurrency(string $c): self                  { $this->currency = strtoupper($c); return $this; }
    public function getAcceptedChains(): array                    { return $this->acceptedChains; }
    public function setAcceptedChains(array $a): self             { $this->acceptedChains = $a; return $this; }
    public function getAcceptedTokens(): array                    { return $this->acceptedTokens; }
    public function setAcceptedTokens(array $a): self             { $this->acceptedTokens = $a; return $this; }
    public function getRecipientAddress(): string                 { return $this->recipientAddress; }
    public function setRecipientAddress(string $a): self          { $this->recipientAddress = strtolower($a); return $this; }
    public function getExpectedPayerAddress(): ?string            { return $this->expectedPayerAddress; }
    public function setExpectedPayerAddress(?string $a): self     { $this->expectedPayerAddress = $a ? strtolower($a) : null; return $this; }
    public function getClaimedTxHash(): ?string                   { return $this->claimedTxHash; }
    public function setClaimedTxHash(?string $h): self            { $this->claimedTxHash = $h ? strtolower($h) : null; return $this; }
    public function getClaimedChainId(): ?int                     { return $this->claimedChainId; }
    public function setClaimedChainId(?int $c): self              { $this->claimedChainId = $c; return $this; }
    public function getClaimedAt(): ?\DateTimeInterface           { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeInterface $t): self    { $this->claimedAt = $t; return $this; }
    public function getPaidAt(): ?\DateTimeInterface              { return $this->paidAt; }
    public function setPaidAt(?\DateTimeInterface $t): self       { $this->paidAt = $t; return $this; }
    public function getPaidFeePayment(): ?FeePayment              { return $this->paidFeePayment; }
    public function setPaidFeePayment(?FeePayment $p): self       { $this->paidFeePayment = $p; return $this; }
    public function getExpiresAt(): \DateTimeInterface            { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeInterface $t): self     { $this->expiresAt = $t; return $this; }
    public function getCreatedAt(): \DateTimeInterface            { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface            { return $this->updatedAt; }

    public function isPending(): bool { return $this->status === BillingIntentStatus::Pending; }
    public function isExpired(): bool { return $this->status === BillingIntentStatus::Expired || $this->expiresAt < new \DateTimeImmutable(); }
    public function touch(): self     { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
