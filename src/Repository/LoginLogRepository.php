<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginLog;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginLog>
 */
class LoginLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginLog::class);
    }

    /**
     * @return LoginLog[]
     */
    public function findRecentByUser(Utilisateur $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('ll')
            ->where('ll.utilisateur = :user')
            ->setParameter('user', $user)
            ->orderBy('ll.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countFailedSince(\DateTimeImmutable $since, ?string $email = null): int
    {
        $qb = $this->createQueryBuilder('ll')
            ->select('COUNT(ll.id)')
            ->where('ll.status = :status')
            ->andWhere('ll.createdAt >= :since')
            ->setParameter('status', 'failure')
            ->setParameter('since', $since);

        if ($email !== null) {
            $qb->andWhere('ll.email = :email')
                ->setParameter('email', $email);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return LoginLog[]
     */
    public function findRecentForOrganisation(Organisation $org, int $limit = 50): array
    {
        return $this->createQueryBuilder('ll')
            ->innerJoin('ll.utilisateur', 'u')
            ->where('u.organisation = :org')
            ->setParameter('org', $org)
            ->orderBy('ll.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('ll')
            ->delete()
            ->where('ll.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
