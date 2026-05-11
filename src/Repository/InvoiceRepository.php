<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 *
 * Phase 2: queries are scoped to a Workspace, not a User. Members of
 * the same workspace see the same invoices, payments, and metrics.
 * `i.user` remains as the "created_by" column for attribution.
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findByUuid(string $uuid): ?Invoice
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Lower-case recipient addresses across every still-payable invoice.
     * Used by the chain listener to compute the topics[2] filter for eth_getLogs.
     *
     * @return string[] e.g. ['0x742d35cc...', '0xabcdef12...']
     */
    public function getOpenRecipientAddresses(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT LOWER(i.recipientAddress) AS addr')
            ->where('i.status IN (:open)')
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->getQuery()
            ->getResult();
        return array_map(static fn(array $r): string => $r['addr'], $rows);
    }

    /**
     * @return Invoice[]
     */
    public function findOpenByRecipient(string $address): array
    {
        return $this->createQueryBuilder('i')
            ->where('LOWER(i.recipientAddress) = :addr')
            ->andWhere('i.status IN (:open)')
            ->setParameter('addr', strtolower($address))
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Invoice $invoice, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($invoice);
        if ($flush) {
            $em->flush();
        }
    }

    /** @return Invoice[] paginated, newest first */
    public function findByWorkspacePaginated(Workspace $workspace, int $page = 1, int $perPage = 25, ?string $statusFilter = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->orderBy('i.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if ($statusFilter !== null && $statusFilter !== '') {
            $qb->andWhere('i.status = :st')->setParameter('st', $statusFilter);
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('i.number LIKE :q OR LOWER(i.clientName) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }
        return $qb->getQuery()->getResult();
    }

    public function countByWorkspace(Workspace $workspace, ?string $statusFilter = null, ?string $search = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.workspace = :ws')
            ->setParameter('ws', $workspace);
        if ($statusFilter !== null && $statusFilter !== '') {
            $qb->andWhere('i.status = :st')->setParameter('st', $statusFilter);
        }
        if ($search !== null && $search !== '') {
            $qb->andWhere('i.number LIKE :q OR LOWER(i.clientName) LIKE :q')
               ->setParameter('q', '%' . strtolower($search) . '%');
        }
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return Invoice[] N most recent regardless of status */
    public function findRecent(Workspace $workspace, int $limit = 5): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sum of amount_cents for invoices issued in the current calendar month
     * (UTC), regardless of status. Used to enforce the Free-plan $1k MTD cap.
     */
    public function monthToDateIssuedCents(Workspace $workspace): int
    {
        $monthStart = (new \DateTimeImmutable('first day of this month 00:00:00'));
        $sum = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.amountCents), 0)')
            ->where('i.workspace = :ws')
            ->andWhere('i.status <> :void')
            ->andWhere('i.issuedAt >= :monthStart')
            ->setParameter('ws', $workspace)
            ->setParameter('void', 'void')
            ->setParameter('monthStart', $monthStart)
            ->getQuery()
            ->getSingleScalarResult();
        return (int) $sum;
    }

    /** Sum of paid amount_cents for the workspace since the given timestamp. */
    public function sumPaidSince(Workspace $workspace, \DateTimeInterface $since): int
    {
        $sum = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.amountCents), 0)')
            ->where('i.workspace = :ws')
            ->andWhere('i.status = :paid')
            ->andWhere('i.paidAt >= :since')
            ->setParameter('ws', $workspace)
            ->setParameter('paid', 'paid')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
        return (int) $sum;
    }

    /** Sum of awaiting (sent / viewed / partially_paid / overdue) amount_cents. */
    public function sumAwaiting(Workspace $workspace): int
    {
        $sum = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.amountCents), 0)')
            ->where('i.workspace = :ws')
            ->andWhere('i.status IN (:open)')
            ->setParameter('ws', $workspace)
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->getQuery()
            ->getSingleScalarResult();
        return (int) $sum;
    }

    /**
     * @return array{total:int, overdue:int}
     */
    public function awaitingBreakdown(Workspace $workspace): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.status, COUNT(i.id) as cnt')
            ->where('i.workspace = :ws')
            ->andWhere('i.status IN (:open)')
            ->setParameter('ws', $workspace)
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();
        $total = 0; $overdue = 0;
        foreach ($rows as $r) {
            $cnt = (int) $r['cnt'];
            $total += $cnt;
            if ($r['status']->value === 'overdue') $overdue = $cnt;
        }
        return ['total' => $total, 'overdue' => $overdue];
    }

    /**
     * Average seconds between createdAt and paidAt for paid invoices.
     * Returns null if no paid invoices yet.
     */
    public function avgSettleTimeSeconds(Workspace $workspace): ?int
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.createdAt, i.paidAt')
            ->where('i.workspace = :ws')
            ->andWhere('i.status = :paid')
            ->andWhere('i.paidAt IS NOT NULL')
            ->setParameter('ws', $workspace)
            ->setParameter('paid', 'paid')
            ->setMaxResults(500)
            ->getQuery()
            ->getArrayResult();
        if (empty($rows)) return null;
        $total = 0; $count = 0;
        foreach ($rows as $r) {
            if ($r['paidAt'] && $r['createdAt']) {
                $total += $r['paidAt']->getTimestamp() - $r['createdAt']->getTimestamp();
                $count++;
            }
        }
        return $count > 0 ? intdiv($total, $count) : null;
    }
}
