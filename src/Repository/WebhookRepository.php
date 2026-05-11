<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Webhook;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Webhook>
 */
class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    /** @return Webhook[] */
    public function findActiveForWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.workspace = :ws')
            ->setParameter('ws', $workspace)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All active webhooks for a workspace that subscribe to the given event.
     * The events array is filtered in PHP because DQL has no native
     * JSON_CONTAINS function (see gotchas).
     *
     * @return Webhook[]
     */
    public function findSubscribersFor(Workspace $workspace, string $event): array
    {
        /** @var Webhook[] $candidates */
        $candidates = $this->createQueryBuilder('w')
            ->where('w.workspace = :ws')
            ->andWhere('w.isActive = :true')
            ->setParameter('ws', $workspace)
            ->setParameter('true', true)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $candidates,
            fn (Webhook $w) => in_array($event, $w->getEvents(), true)
        ));
    }
}
