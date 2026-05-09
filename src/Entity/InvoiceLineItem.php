<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_line_items')]
class InvoiceLineItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\Column(length: 500)]
    private string $description;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '1.00'])]
    private string $quantity = '1.00';

    #[ORM\Column(name: 'unit_price_cents', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $unitPriceCents;

    #[ORM\Column(name: 'total_cents', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $totalCents;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $position = 0;

    public function getId(): ?string                  { return $this->id; }
    public function getInvoice(): Invoice             { return $this->invoice; }
    public function setInvoice(Invoice $i): self      { $this->invoice = $i; return $this; }
    public function getDescription(): string          { return $this->description; }
    public function setDescription(string $d): self   { $this->description = $d; return $this; }
    public function getQuantity(): string             { return $this->quantity; }
    public function setQuantity(string $q): self      { $this->quantity = $q; return $this; }
    public function getUnitPriceCents(): int          { return (int) $this->unitPriceCents; }
    public function setUnitPriceCents(int $c): self   { $this->unitPriceCents = (string) $c; return $this; }
    public function getTotalCents(): int              { return (int) $this->totalCents; }
    public function setTotalCents(int $c): self       { $this->totalCents = (string) $c; return $this; }
    public function getPosition(): int                { return $this->position; }
    public function setPosition(int $p): self         { $this->position = $p; return $this; }
}
