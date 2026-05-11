<?php

namespace App\Entity;

use App\Repository\FeePaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An on-chain Transfer received at the platform wallet — the billing-side
 * equivalent of `payments` (which tracks Transfers received at freelancer
 * wallets for invoices).
 *
 * Idempotent by (chain_id, tx_hash, log_index) so re-processing the same
 * log is a no-op. Linked to a BillingIntent when we can identify the
 * payer; otherwise stored unattached (orphan from the platform's POV —
 * either an unsolicited tip or a payment with a typo'd amount).
 */
#[ORM\Entity(repositoryClass: FeePaymentRepository::class)]
#[ORM\Table(name: 'fee_payments')]
class FeePayment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: BillingIntent::class)]
    #[ORM\JoinColumn(name: 'billing_intent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?BillingIntent $billingIntent = null;

    #[ORM\Column(name: 'chain_id', type: Types::INTEGER, options: ['unsigned' => true])]
    private int $chainId;

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

    #[ORM\Column(name: 'amount_raw', length: 100)]
    private string $amountRaw;

    #[ORM\Column(name: 'amount_usd_cents', type: Types::BIGINT, options: ['unsigned' => true], nullable: true)]
    private ?string $amountUsdCents = null;

    #[ORM\Column(name: 'payer_address', length: 64)]
    private string $payerAddress;

    #[ORM\Column(name: 'recipient_address', length: 64)]
    private string $recipientAddress;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $confirmations = 0;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string                              { return $this->id; }
    public function getUser(): User                               { return $this->user; }
    public function setUser(User $u): self                        { $this->user = $u; return $this; }
    public function getBillingIntent(): ?BillingIntent            { return $this->billingIntent; }
    public function setBillingIntent(?BillingIntent $i): self     { $this->billingIntent = $i; return $this; }
    public function getChainId(): int                             { return $this->chainId; }
    public function setChainId(int $c): self                      { $this->chainId = $c; return $this; }
    public function getTxHash(): string                           { return $this->txHash; }
    public function setTxHash(string $h): self                    { $this->txHash = strtolower($h); return $this; }
    public function getLogIndex(): int                            { return $this->logIndex; }
    public function setLogIndex(int $i): self                     { $this->logIndex = $i; return $this; }
    public function getBlockNumber(): string                      { return $this->blockNumber; }
    public function setBlockNumber(string $b): self               { $this->blockNumber = $b; return $this; }
    public function getBlockTimestamp(): \DateTimeInterface       { return $this->blockTimestamp; }
    public function setBlockTimestamp(\DateTimeInterface $t): self{ $this->blockTimestamp = $t; return $this; }
    public function getTokenAddress(): string                     { return $this->tokenAddress; }
    public function setTokenAddress(string $a): self              { $this->tokenAddress = strtolower($a); return $this; }
    public function getTokenSymbol(): string                      { return $this->tokenSymbol; }
    public function setTokenSymbol(string $s): self               { $this->tokenSymbol = $s; return $this; }
    public function getTokenDecimals(): int                       { return $this->tokenDecimals; }
    public function setTokenDecimals(int $d): self                { $this->tokenDecimals = $d; return $this; }
    public function getAmountRaw(): string                        { return $this->amountRaw; }
    public function setAmountRaw(string $a): self                 { $this->amountRaw = $a; return $this; }
    public function getAmountUsdCents(): ?int                     { return $this->amountUsdCents !== null ? (int) $this->amountUsdCents : null; }
    public function setAmountUsdCents(?int $c): self              { $this->amountUsdCents = $c !== null ? (string) $c : null; return $this; }
    public function getPayerAddress(): string                     { return $this->payerAddress; }
    public function setPayerAddress(string $a): self              { $this->payerAddress = strtolower($a); return $this; }
    public function getRecipientAddress(): string                 { return $this->recipientAddress; }
    public function setRecipientAddress(string $a): self          { $this->recipientAddress = strtolower($a); return $this; }
    public function getConfirmations(): int                       { return $this->confirmations; }
    public function setConfirmations(int $c): self                { $this->confirmations = $c; return $this; }
    public function getConfirmedAt(): ?\DateTimeInterface         { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeInterface $t): self  { $this->confirmedAt = $t; return $this; }
    public function getCreatedAt(): \DateTimeInterface            { return $this->createdAt; }
}
