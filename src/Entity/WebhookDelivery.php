<?php

namespace App\Entity;

use App\Repository\WebhookDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per attempt-set to deliver an event to a specific webhook
 * endpoint. Retried up to N times by a Messenger handler. `delivered_at`
 * is non-null on success.
 */
#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_deliveries')]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Webhook::class)]
    #[ORM\JoinColumn(name: 'webhook_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Webhook $webhook;

    #[ORM\Column(length: 60)]
    private string $event;

    #[ORM\Column(name: 'payload_json', type: Types::TEXT)]
    private string $payloadJson;

    #[ORM\Column(name: 'attempt_count', type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $attemptCount = 0;

    #[ORM\Column(name: 'last_status_code', type: Types::INTEGER, nullable: true)]
    private ?int $lastStatusCode = null;

    #[ORM\Column(name: 'last_response_body', type: Types::TEXT, nullable: true)]
    private ?string $lastResponseBody = null;

    #[ORM\Column(name: 'last_error', length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $deliveredAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?string                              { return $this->id; }
    public function getWebhook(): Webhook                         { return $this->webhook; }
    public function setWebhook(Webhook $w): self                  { $this->webhook = $w; return $this; }
    public function getEvent(): string                            { return $this->event; }
    public function setEvent(string $e): self                     { $this->event = $e; return $this; }
    public function getPayloadJson(): string                      { return $this->payloadJson; }
    public function setPayloadJson(string $p): self               { $this->payloadJson = $p; return $this; }
    public function getAttemptCount(): int                        { return $this->attemptCount; }
    public function incrementAttempts(): self                     { $this->attemptCount++; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getLastStatusCode(): ?int                     { return $this->lastStatusCode; }
    public function setLastStatusCode(?int $c): self              { $this->lastStatusCode = $c; return $this; }
    public function getLastResponseBody(): ?string                { return $this->lastResponseBody; }
    public function setLastResponseBody(?string $b): self         { $this->lastResponseBody = $b; return $this; }
    public function getLastError(): ?string                       { return $this->lastError; }
    public function setLastError(?string $e): self                { $this->lastError = $e; return $this; }
    public function getDeliveredAt(): ?\DateTimeInterface         { return $this->deliveredAt; }
    public function markDelivered(): self                         { $this->deliveredAt = new \DateTimeImmutable(); $this->updatedAt = $this->deliveredAt; return $this; }
    public function getCreatedAt(): \DateTimeInterface            { return $this->createdAt; }
}
