<?php

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceInvitation>
 */
class WorkspaceInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceInvitation::class);
    }

    public function findByToken(string $token): ?WorkspaceInvitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return WorkspaceInvitation[] */
    public function findPendingFor(Workspace $w): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.workspace = :w')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('w', $w)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return WorkspaceInvitation[] */
    public function findPendingByEmail(string $email): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.email = :email')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('email', strtolower($email))
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
