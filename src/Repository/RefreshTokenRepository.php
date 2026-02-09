<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * @return RefreshToken[]
     */
    public function findActiveByUsername(string $username): array
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.username = :username')
            ->andWhere('rt.valid >= :now')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('username', $username)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    public function revokeAllForUser(string $username): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.username = :username')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('username', $username)
            ->getQuery()
            ->execute();
    }

    public function purgeExpiredAndRevoked(): int
    {
        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.valid < :now')
            ->orWhere('rt.revokedAt IS NOT NULL')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->execute();
    }
}
