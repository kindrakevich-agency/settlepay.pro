<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Returns true if we've already recorded this exact (chain, tx, log) tuple.
     * Lets the listener be idempotent — re-processing the same range is a no-op.
     */
    public function existsByTriple(int $chainId, string $txHash, int $logIndex): bool
    {
        return null !== $this->findOneBy([
            'chainId'  => $chainId,
            'txHash'   => strtolower($txHash),
            'logIndex' => $logIndex,
        ]);
    }

    public function save(Payment $p, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($p);
        if ($flush) {
            $em->flush();
        }
    }
}
