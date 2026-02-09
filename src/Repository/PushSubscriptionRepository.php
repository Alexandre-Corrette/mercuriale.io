<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * @return PushSubscription[]
     */
    public function findByUser(Utilisateur $user): array
    {
        return $this->findBy(['utilisateur' => $user]);
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.endpoint = :endpoint')
            ->setParameter('endpoint', $endpoint)
            ->getQuery()
            ->execute();
    }

    /**
     * @param int[] $userIds
     * @return PushSubscription[]
     */
    public function findByUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->join('p.utilisateur', 'u')
            ->where('u.id IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getResult();
    }
}
