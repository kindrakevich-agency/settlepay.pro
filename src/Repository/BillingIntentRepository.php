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
     * If two intents have the exact same amount (e.g. two Pro-monthly
     * upgrades in the same window), we prefer the one whose
     * expected_payer_address matches the on-chain `from`; otherwise the
     * oldest pending intent.
     *
     * Implementation note: we deliberately don't filter accepted_chains
     * in DQL — Doctrine's DQL parser doesn't ship JSON_CONTAINS as a
     * known function (registering it requires a custom DQL function +
     * config). Pending intents are few (<100 in practice), so loading
     * the small set and chain-matching in PHP is both simpler and fast.
     */
    public function findMatchingForPayment(
        int $chainId,
        int $amountCents,
        string $payerAddress,
        int $toleranceBps = 50,
    ): ?BillingIntent {
        $qb = $this->createQueryBuilder('i')
            ->where('i.status = :pending')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('pending', BillingIntentStatus::Pending->value)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'ASC');

        /** @var BillingIntent[] $candidates */
        $candidates = $qb->getQuery()->getResult();

        $best = null;
        foreach ($candidates as $intent) {
            // Chain filter — PHP-side instead of JSON_CONTAINS DQL.
            if (!in_array($chainId, $intent->getAcceptedChains(), true)) {
                continue;
            }

            // Within ±toleranceBps of expected amount.
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

    /**
     * Distinct chain IDs referenced by any pending non-expired billing
     * intent OR any intent created in the last 24h (late-payer grace).
     * The listener uses this to skip polling chains that have no active
     * billing activity.
     *
     * @return int[]
     */
    public function getActiveChainIds(): array
    {
        $now    = new \DateTimeImmutable();
        $window = $now->modify('-24 hours');
        $rows = $this->createQueryBuilder('i')
            ->select('i.acceptedChains')
            ->where('(i.status = :pending AND i.expiresAt > :now) OR i.createdAt > :window')
            ->setParameter('pending', BillingIntentStatus::Pending->value)
            ->setParameter('now', $now)
            ->setParameter('window', $window)
            ->getQuery()
            ->getResult();

        $chains = [];
        foreach ($rows as $r) {
            foreach (($r['acceptedChains'] ?? []) as $c) {
                $chains[(int) $c] = true;
            }
        }
        return array_keys($chains);
    }

    /**
     * Should the listener be watching the platform wallet right now?
     *
     * Two conditions:
     *   - Active checkout: at least one pending non-expired intent.
     *   - Late-payer grace window: at least one intent CREATED in the
     *     last 24h, regardless of status. Catches users who started
     *     checkout, let the 1h pending TTL expire, and paid anyway.
     *
     * If neither is true, the listener can safely skip the platform
     * wallet from its eth_getLogs filter — saves a big chunk of Alchemy
     * compute on idle prod (the alternative is polling the wallet 24/7
     * just in case).
     */
    public function shouldWatchPlatformWallet(): bool
    {
        $now    = new \DateTimeImmutable();
        $window = $now->modify('-24 hours');
        $count  = (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('(i.status = :pending AND i.expiresAt > :now) OR i.createdAt > :window')
            ->setParameter('pending', BillingIntentStatus::Pending->value)
            ->setParameter('now', $now)
            ->setParameter('window', $window)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
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
