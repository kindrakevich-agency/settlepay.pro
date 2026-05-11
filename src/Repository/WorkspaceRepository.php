<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workspace>
 */
class WorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    /** @return Workspace[] */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->innerJoin(WorkspaceMember::class, 'm', 'WITH', 'm.workspace = w AND m.user = :u')
            ->setParameter('u', $user)
            ->orderBy('w.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByUuid(string $uuid): ?Workspace
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
