<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\RefreshTokenRepositoryInterface;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 *
 * @implements RefreshTokenRepositoryInterface<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    /**
     * @return RefreshToken[]
     */
    public function findInvalid($datetime = null): array
    {
        $qb = $this->createQueryBuilder('rt');

        if ($datetime !== null) {
            $qb->where('rt.valid < :datetime')
                ->setParameter('datetime', $datetime);
        } else {
            $qb->where('rt.valid < :now')
                ->setParameter('now', new \DateTime());
        }

        return $qb->getQuery()->getResult();
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
