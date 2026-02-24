<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MercurialeImport;
use App\Entity\Utilisateur;
use App\Enum\StatutImport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MercurialeImport>
 */
class MercurialeImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MercurialeImport::class);
    }

    public function findByUuid(string $uuid): ?MercurialeImport
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($uuid)]);
    }

    /**
     * @return MercurialeImport[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('mi')
            ->where('mi.expiresAt < :now')
            ->andWhere('mi.status NOT IN (:finalStatuses)')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('finalStatuses', [
                StatutImport::COMPLETED->value,
                StatutImport::EXPIRED->value,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MercurialeImport[]
     */
    public function findByUser(Utilisateur $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('mi')
            ->where('mi.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('mi.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MercurialeImport[]
     */
    public function findPendingByUser(Utilisateur $user): array
    {
        return $this->createQueryBuilder('mi')
            ->where('mi.createdBy = :user')
            ->andWhere('mi.status IN (:pendingStatuses)')
            ->andWhere('mi.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('pendingStatuses', [
                StatutImport::PENDING->value,
                StatutImport::MAPPING->value,
                StatutImport::PREVIEWED->value,
            ])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('mi.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countRecentByUser(Utilisateur $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('mi')
            ->select('COUNT(mi.id)')
            ->where('mi.createdBy = :user')
            ->andWhere('mi.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('mi')
            ->delete()
            ->where('mi.expiresAt < :threshold')
            ->andWhere('mi.status IN (:deletableStatuses)')
            ->setParameter('threshold', new \DateTimeImmutable('-24 hours'))
            ->setParameter('deletableStatuses', [
                StatutImport::EXPIRED->value,
                StatutImport::FAILED->value,
            ])
            ->getQuery()
            ->execute();
    }
}
