<?php

namespace App\Entity;

use App\Repository\ChainCursorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks how far the listener has processed each chain.
 * One row per chain_id. Updated atomically after each batch.
 */
#[ORM\Entity(repositoryClass: ChainCursorRepository::class)]
#[ORM\Table(name: 'chain_cursors')]
class ChainCursor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?string $id = null;

    #[ORM\Column(name: 'chain_id', type: Types::INTEGER, unique: true, options: ['unsigned' => true])]
    private int $chainId;

    #[ORM\Column(name: 'last_processed_block', type: Types::BIGINT, options: ['unsigned' => true])]
    private string $lastProcessedBlock = '0';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct(int $chainId, int|string $startBlock = 0)
    {
        $this->chainId            = $chainId;
        $this->lastProcessedBlock = (string) $startBlock;
        $this->updatedAt          = new \DateTimeImmutable();
    }

    public function getId(): ?string                       { return $this->id; }
    public function getChainId(): int                      { return $this->chainId; }
    public function getLastProcessedBlock(): int           { return (int) $this->lastProcessedBlock; }
    public function setLastProcessedBlock(int $b): self    { $this->lastProcessedBlock = (string) $b; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getUpdatedAt(): \DateTimeInterface     { return $this->updatedAt; }
}
