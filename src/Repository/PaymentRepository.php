<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Workspace;
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

    /**
     * Returns payments visible to a workspace — both matched (linked to
     * one of its invoices) and orphan (sent to the workspace's current
     * payout address but no invoice matched).
     *
     * @return Payment[]
     */
    public function findForWorkspacePaginated(Workspace $workspace, int $page = 1, int $perPage = 25, ?string $kind = null, ?int $chainId = null): array
    {
        $qb = $this->qbForWorkspace($workspace, $kind, $chainId);
        return $qb
            ->orderBy('p.confirmedAt', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForWorkspace(Workspace $workspace, ?string $kind = null, ?int $chainId = null): int
    {
        $qb = $this->qbForWorkspace($workspace, $kind, $chainId);
        return (int) $qb->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Aggregate: total USD value confirmed for the workspace (matched only).
     * Orphan payments aren't credited to any invoice so we don't sum them.
     */
    public function sumMatchedUsdCentsForWorkspace(Workspace $workspace): int
    {
        $sum = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountUsdCents), 0)')
            ->leftJoin('p.invoice', 'i')
            ->where('i.workspace = :ws')
            ->andWhere('p.amountUsdCents IS NOT NULL')
            ->setParameter('ws', $workspace)
            ->getQuery()
            ->getSingleScalarResult();
        return (int) $sum;
    }

    private function qbForWorkspace(Workspace $workspace, ?string $kind, ?int $chainId): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.invoice', 'i')
            ->where('i.workspace = :ws OR (p.invoice IS NULL AND p.recipientAddress = :addr)')
            ->setParameter('ws', $workspace)
            ->setParameter('addr', strtolower($workspace->getPayoutAddress()));

        if ($kind === 'matched') {
            $qb->andWhere('p.invoice IS NOT NULL');
        } elseif ($kind === 'orphan') {
            $qb->andWhere('p.invoice IS NULL');
        }
        if ($chainId !== null) {
            $qb->andWhere('p.chainId = :cid')->setParameter('cid', $chainId);
        }
        return $qb;
    }
}
