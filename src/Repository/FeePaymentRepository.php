<?php

namespace App\Repository;

use App\Entity\FeePayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeePayment>
 */
class FeePaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeePayment::class);
    }

    public function save(FeePayment $p, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($p);
        if ($flush) {
            $em->flush();
        }
    }

    /** Idempotency check: same (chain, tx, log_index) already imported? */
    public function existsByTriple(int $chainId, string $txHash, int $logIndex): bool
    {
        return (bool) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.chainId = :c AND p.txHash = :t AND p.logIndex = :l')
            ->setParameter('c', $chainId)
            ->setParameter('t', strtolower($txHash))
            ->setParameter('l', $logIndex)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
