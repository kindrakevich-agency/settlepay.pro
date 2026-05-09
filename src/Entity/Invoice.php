<?php

namespace App\Entity;

use App\Enum\InvoiceStatus;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[ORM\Index(name: 'idx_invoices_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_invoices_status',      columns: ['status'])]
#[ORM\Index(name: 'idx_invoices_uuid',        columns: ['uuid'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    /** Public-facing identifier. The /pay/{uuid} URL uses this. */
    #[ORM\Column(type: Types::GUID, length: 36, unique: true)]
    private string $uuid;

    /** Human-readable invoice number, e.g. INV-2026-0042 */
    #[ORM\Column(length: 40)]
    private string $number;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceStatus::class, options: ['default' => 'draft'])]
    private InvoiceStatus $status = InvoiceStatus::Draft;

    /** Total amount in integer cents — never use float for money. */
    #[ORM\Column(name: 'amount_cents', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $amountCents;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'client_name', length: 180)]
    private string $clientName;

    #[ORM\Column(name: 'client_email', length: 255, nullable: true)]
    private ?string $clientEmail = null;

    #[ORM\Column(name: 'client_address', type: Types::TEXT, nullable: true)]
    private ?string $clientAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(name: 'issued_at', type: Types::DATE_IMMUTABLE)]
    private \DateTimeInterface $issuedAt;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(name: 'viewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $viewedAt = null;

    /** @var int[] List of chain IDs this invoice accepts. */
    #[ORM\Column(name: 'accepted_chains', type: Types::JSON)]
    private array $acceptedChains = [];

    /** @var string[] List of token symbols this invoice accepts (USDC, USDT, DAI). */
    #[ORM\Column(name: 'accepted_tokens', type: Types::JSON)]
    private array $acceptedTokens = [];

    /** Lower-case wallet address that should receive payment. Snapshot of user.payout_address at create time. */
    #[ORM\Column(name: 'recipient_address', length: 64)]
    private string $recipientAddress;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    /** @var Collection<int, InvoiceLineItem> */
    #[ORM\OneToMany(targetEntity: InvoiceLineItem::class, mappedBy: 'invoice', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lineItems;

    public function __construct()
    {
        $this->uuid      = Uuid::v7()->toRfc4122();
        $this->issuedAt  = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->lineItems = new ArrayCollection();
    }

    public function getId(): ?string                       { return $this->id; }
    public function getUuid(): string                      { return $this->uuid; }
    public function getNumber(): string                    { return $this->number; }
    public function setNumber(string $n): self             { $this->number = $n; return $this; }
    public function getUser(): User                        { return $this->user; }
    public function setUser(User $u): self                 { $this->user = $u; return $this; }
    public function getStatus(): InvoiceStatus             { return $this->status; }
    public function setStatus(InvoiceStatus $s): self      { $this->status = $s; return $this; }
    public function getAmountCents(): int                  { return (int) $this->amountCents; }
    public function setAmountCents(int $c): self           { $this->amountCents = (string) $c; return $this; }
    public function getCurrency(): string                  { return $this->currency; }
    public function setCurrency(string $c): self           { $this->currency = $c; return $this; }
    public function getClientName(): string                { return $this->clientName; }
    public function setClientName(string $n): self         { $this->clientName = $n; return $this; }
    public function getClientEmail(): ?string              { return $this->clientEmail; }
    public function setClientEmail(?string $e): self       { $this->clientEmail = $e; return $this; }
    public function getClientAddress(): ?string            { return $this->clientAddress; }
    public function setClientAddress(?string $a): self     { $this->clientAddress = $a; return $this; }
    public function getDescription(): ?string              { return $this->description; }
    public function setDescription(?string $d): self       { $this->description = $d; return $this; }
    public function getNotes(): ?string                    { return $this->notes; }
    public function setNotes(?string $n): self             { $this->notes = $n; return $this; }
    public function getDueDate(): ?\DateTimeInterface      { return $this->dueDate; }
    public function setDueDate(?\DateTimeInterface $d): self { $this->dueDate = $d; return $this; }
    public function getIssuedAt(): \DateTimeInterface      { return $this->issuedAt; }
    public function setIssuedAt(\DateTimeInterface $d): self { $this->issuedAt = $d; return $this; }
    public function getPaidAt(): ?\DateTimeInterface       { return $this->paidAt; }
    public function setPaidAt(?\DateTimeInterface $d): self { $this->paidAt = $d; return $this; }
    public function getViewedAt(): ?\DateTimeInterface     { return $this->viewedAt; }
    public function setViewedAt(?\DateTimeInterface $d): self { $this->viewedAt = $d; return $this; }
    public function getAcceptedChains(): array             { return $this->acceptedChains; }
    public function setAcceptedChains(array $c): self      { $this->acceptedChains = array_values(array_map('intval', $c)); return $this; }
    public function getAcceptedTokens(): array             { return $this->acceptedTokens; }
    public function setAcceptedTokens(array $t): self      { $this->acceptedTokens = array_values(array_map('strtoupper', $t)); return $this; }
    public function getRecipientAddress(): string          { return $this->recipientAddress; }
    public function setRecipientAddress(string $a): self   { $this->recipientAddress = strtolower($a); return $this; }
    public function getMetadata(): ?array                  { return $this->metadata; }
    public function setMetadata(?array $m): self           { $this->metadata = $m; return $this; }
    public function getCreatedAt(): \DateTimeInterface     { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface     { return $this->updatedAt; }
    public function touch(): self                          { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, InvoiceLineItem> */
    public function getLineItems(): Collection { return $this->lineItems; }

    public function addLineItem(InvoiceLineItem $item): self
    {
        if (!$this->lineItems->contains($item)) {
            $this->lineItems->add($item);
            $item->setInvoice($this);
        }
        return $this;
    }

    /** True if this invoice can still receive a payment via the public page. */
    public function isPayable(): bool { return $this->status->isPayable(); }

    /** Mark as viewed if not already. Returns true on the first view. */
    public function markViewed(): bool
    {
        if ($this->viewedAt === null && $this->status === InvoiceStatus::Sent) {
            $this->viewedAt = new \DateTimeImmutable();
            $this->status   = InvoiceStatus::Viewed;
            $this->touch();
            return true;
        }
        return false;
    }
}
