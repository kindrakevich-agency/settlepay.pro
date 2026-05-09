<?php

namespace App\Repository;

use App\Entity\ChainCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChainCursor>
 */
class ChainCursorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChainCursor::class);
    }

    public function findByChainId(int $chainId): ?ChainCursor
    {
        return $this->findOneBy(['chainId' => $chainId]);
    }

    public function getOrCreate(int $chainId, int $startBlock = 0): ChainCursor
    {
        $cursor = $this->findByChainId($chainId);
        if ($cursor === null) {
            $cursor = new ChainCursor($chainId, $startBlock);
            $em = $this->getEntityManager();
            $em->persist($cursor);
            $em->flush();
        }
        return $cursor;
    }

    public function save(ChainCursor $cursor, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($cursor);
        if ($flush) {
            $em->flush();
        }
    }
}
