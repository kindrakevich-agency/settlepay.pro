<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'uniq_tx', columns: ['chain_id', 'tx_hash', 'log_index'])]
#[ORM\Index(name: 'idx_payments_invoice',   columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_payments_recipient', columns: ['recipient_address'])]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    /** Null when we received a Transfer that doesn't match any known invoice. */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    #[ORM\Column(name: 'chain_id', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $chainId;

    /** Lower-case 0x-prefixed 32-byte tx hash. */
    #[ORM\Column(name: 'tx_hash', length: 80)]
    private string $txHash;

    #[ORM\Column(name: 'log_index', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $logIndex;

    #[ORM\Column(name: 'block_number', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $blockNumber;

    #[ORM\Column(name: 'block_timestamp', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $blockTimestamp;

    #[ORM\Column(name: 'token_address', length: 64)]
    private string $tokenAddress;

    #[ORM\Column(name: 'token_symbol', length: 20)]
    private string $tokenSymbol;

    #[ORM\Column(name: 'token_decimals', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $tokenDecimals;

    /** uint256 stringified — never stored as a number type to preserve precision. */
    #[ORM\Column(name: 'amount_raw', length: 100)]
    private string $amountRaw;

    /** USD value snapshotted at confirmation time. NULL if pricing failed. */
    #[ORM\Column(name: 'amount_usd_cents', type: Types::BIGINT, nullable: true, options: ['unsigned' => true])]
    private ?string $amountUsdCents = null;

    #[ORM\Column(name: 'payer_address', length: 64)]
    private string $payerAddress;

    #[ORM\Column(name: 'recipient_address', length: 64)]
    private string $recipientAddress;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $confirmations = 0;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string                       { return $this->id; }
    public function getInvoice(): ?Invoice                 { return $this->invoice; }
    public function setInvoice(?Invoice $i): self          { $this->invoice = $i; return $this; }
    public function getChainId(): int                      { return $this->chainId; }
    public function setChainId(int $id): self              { $this->chainId = $id; return $this; }
    public function getTxHash(): string                    { return $this->txHash; }
    public function setTxHash(string $h): self             { $this->txHash = strtolower($h); return $this; }
    public function getLogIndex(): int                     { return $this->logIndex; }
    public function setLogIndex(int $i): self              { $this->logIndex = $i; return $this; }
    public function getBlockNumber(): string               { return $this->blockNumber; }
    public function setBlockNumber(string $n): self        { $this->blockNumber = $n; return $this; }
    public function getBlockTimestamp(): \DateTimeInterface { return $this->blockTimestamp; }
    public function setBlockTimestamp(\DateTimeInterface $d): self { $this->blockTimestamp = $d; return $this; }
    public function getTokenAddress(): string              { return $this->tokenAddress; }
    public function setTokenAddress(string $a): self       { $this->tokenAddress = strtolower($a); return $this; }
    public function getTokenSymbol(): string               { return $this->tokenSymbol; }
    public function setTokenSymbol(string $s): self        { $this->tokenSymbol = strtoupper($s); return $this; }
    public function getTokenDecimals(): int                { return $this->tokenDecimals; }
    public function setTokenDecimals(int $d): self         { $this->tokenDecimals = $d; return $this; }
    public function getAmountRaw(): string                 { return $this->amountRaw; }
    public function setAmountRaw(string $a): self          { $this->amountRaw = $a; return $this; }
    public function getAmountUsdCents(): ?int              { return $this->amountUsdCents !== null ? (int) $this->amountUsdCents : null; }
    public function setAmountUsdCents(?int $c): self       { $this->amountUsdCents = $c !== null ? (string) $c : null; return $this; }
    public function getPayerAddress(): string              { return $this->payerAddress; }
    public function setPayerAddress(string $a): self       { $this->payerAddress = strtolower($a); return $this; }
    public function getRecipientAddress(): string          { return $this->recipientAddress; }
    public function setRecipientAddress(string $a): self   { $this->recipientAddress = strtolower($a); return $this; }
    public function getConfirmations(): int                { return $this->confirmations; }
    public function setConfirmations(int $c): self         { $this->confirmations = $c; return $this; }
    public function getConfirmedAt(): ?\DateTimeInterface  { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeInterface $d): self { $this->confirmedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeInterface     { return $this->createdAt; }
}
