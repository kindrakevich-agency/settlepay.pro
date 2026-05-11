<?php

namespace App\Repository;

use App\Entity\BillingIntent;
use App\Enum\BillingIntentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BillingIntent>
 */
class BillingIntentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingIntent::class);
    }

    public function save(BillingIntent $i, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($i);
        if ($flush) {
            $em->flush();
        }
    }

    public function findByUuid(string $uuid): ?BillingIntent
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * The listener calls this for every incoming Transfer to the platform
     * wallet. Match by chain + amount within tolerance, status=pending.
     *
     * If two intents on the same chain happen to have the exact same
     * amount (e.g. two Pro-monthly upgrades from different users in the
     * same window), we prefer the one whose expected_payer_address
     * matches the on-chain `from`; otherwise the oldest pending intent.
     */
    public function findMatchingForPayment(
        int $chainId,
        int $amountCents,
        string $payerAddress,
        int $toleranceBps = 50,
    ): ?BillingIntent {
        $qb = $this->createQueryBuilder('i')
            ->where('i.status = :pending')
            ->andWhere('JSON_CONTAINS(i.acceptedChains, :chainJson) = 1')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('pending', BillingIntentStatus::Pending->value)
            ->setParameter('chainJson', (string) $chainId)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'ASC');

        /** @var BillingIntent[] $candidates */
        $candidates = $qb->getQuery()->getResult();

        $tolerance = (int) ceil(0); // recomputed per-candidate below

        $best = null;
        foreach ($candidates as $intent) {
            // Within ±toleranceBps of expected amount
            $expected = $intent->getAmountCents();
            $diff     = abs($amountCents - $expected);
            $maxDiff  = (int) ceil(($expected * $toleranceBps) / 10000);
            if ($diff > $maxDiff) {
                continue;
            }

            // Prefer payer match if specified.
            if ($intent->getExpectedPayerAddress()) {
                if ($intent->getExpectedPayerAddress() === strtolower($payerAddress)) {
                    return $intent;
                }
                continue; // payer-locked intent with wrong from — skip
            }

            if ($best === null) {
                $best = $intent;
            }
        }
        return $best;
    }

    /** Bulk-expire pending intents past their TTL — called by app:billing:check-renewals (Phase 2). */
    public function expireStale(): int
    {
        return $this->createQueryBuilder('i')
            ->update()
            ->set('i.status', ':expired')
            ->set('i.updatedAt', ':now')
            ->where('i.status = :pending')
            ->andWhere('i.expiresAt < :now')
            ->setParameter('expired', BillingIntentStatus::Expired->value)
            ->setParameter('pending', BillingIntentStatus::Pending->value)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
