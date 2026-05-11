<?php

namespace App\Entity;

use App\Repository\WebhookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\Table(name: 'webhooks')]
class Webhook
{
    public const EVENT_INVOICE_SENT     = 'invoice.sent';
    public const EVENT_INVOICE_PAID     = 'invoice.paid';
    public const EVENT_INVOICE_VOIDED   = 'invoice.voided';
    public const EVENT_PAYMENT_RECEIVED = 'payment.received';

    public const ALL_EVENTS = [
        self::EVENT_INVOICE_SENT,
        self::EVENT_INVOICE_PAID,
        self::EVENT_INVOICE_VOIDED,
        self::EVENT_PAYMENT_RECEIVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(name: 'signing_secret', length: 128)]
    private string $signingSecret;

    #[ORM\Column(type: Types::JSON)]
    private array $events = [];

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => 1])]
    private bool $isActive = true;

    #[ORM\Column(name: 'last_success_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSuccessAt = null;

    #[ORM\Column(name: 'last_failure_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastFailureAt = null;

    #[ORM\Column(name: 'last_failure_reason', length: 255, nullable: true)]
    private ?string $lastFailureReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?string                              { return $this->id; }
    public function getUser(): User                               { return $this->user; }
    public function setUser(User $u): self                        { $this->user = $u; return $this; }
    public function getUrl(): string                              { return $this->url; }
    public function setUrl(string $u): self                       { $this->url = $u; return $this; }
    public function getSigningSecret(): string                    { return $this->signingSecret; }
    public function setSigningSecret(string $s): self             { $this->signingSecret = $s; return $this; }
    public function getEvents(): array                            { return $this->events; }
    public function setEvents(array $e): self                     { $this->events = $e; return $this; }
    public function isActive(): bool                              { return $this->isActive; }
    public function setIsActive(bool $a): self                    { $this->isActive = $a; return $this; }
    public function getLastSuccessAt(): ?\DateTimeInterface       { return $this->lastSuccessAt; }
    public function setLastSuccessAt(?\DateTimeInterface $t): self{ $this->lastSuccessAt = $t; return $this; }
    public function getLastFailureAt(): ?\DateTimeInterface       { return $this->lastFailureAt; }
    public function setLastFailureAt(?\DateTimeInterface $t): self{ $this->lastFailureAt = $t; return $this; }
    public function getLastFailureReason(): ?string               { return $this->lastFailureReason; }
    public function setLastFailureReason(?string $r): self        { $this->lastFailureReason = $r; return $this; }
    public function getCreatedAt(): \DateTimeInterface            { return $this->createdAt; }

    public function subscribesTo(string $event): bool
    {
        return $this->isActive && in_array($event, $this->events, true);
    }
}
