<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceMember>
 */
class WorkspaceMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceMember::class);
    }

    public function findFor(Workspace $w, User $u): ?WorkspaceMember
    {
        return $this->findOneBy(['workspace' => $w, 'user' => $u]);
    }

    /** @return WorkspaceMember[] */
    public function findInWorkspace(Workspace $w): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.workspace = :w')
            ->setParameter('w', $w)
            ->orderBy('m.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countInWorkspace(Workspace $w): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.user)')
            ->where('m.workspace = :w')
            ->setParameter('w', $w)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
