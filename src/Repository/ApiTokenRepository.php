<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Look up candidate tokens by their visible prefix. We then
     * password_verify() the full plaintext against each candidate's
     * hash to find the real one. Index on token_prefix makes this O(1)
     * in practice — collisions are astronomically rare with 16 chars
     * of base64url.
     *
     * @return ApiToken[]
     */
    public function findActiveByPrefix(string $prefix): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tokenPrefix = :prefix')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt IS NULL OR t.expiresAt > :now')
            ->setParameter('prefix', $prefix)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** @return ApiToken[] */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :u')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('u', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
