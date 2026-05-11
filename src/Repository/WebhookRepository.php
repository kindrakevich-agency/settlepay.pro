<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Webhook;
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
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :u')
            ->setParameter('u', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All active webhooks for a user that subscribe to the given event.
     * The events array is filtered in PHP because DQL has no native
     * JSON_CONTAINS function (see gotchas).
     *
     * @return Webhook[]
     */
    public function findSubscribersFor(User $user, string $event): array
    {
        /** @var Webhook[] $candidates */
        $candidates = $this->createQueryBuilder('w')
            ->where('w.user = :u')
            ->andWhere('w.isActive = :true')
            ->setParameter('u', $user)
            ->setParameter('true', true)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $candidates,
            fn (Webhook $w) => in_array($event, $w->getEvents(), true)
        ));
    }
}
