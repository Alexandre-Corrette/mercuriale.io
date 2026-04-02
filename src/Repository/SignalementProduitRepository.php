<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\SignalementProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SignalementProduit>
 */
class SignalementProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SignalementProduit::class);
    }

    /**
     * @return SignalementProduit[]
     */
    public function findByEtablissement(Etablissement $etablissement): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByEtablissementAndDate(Etablissement $etablissement, \DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.etablissement = :etablissement')
            ->andWhere('s.createdAt >= :start')
            ->andWhere('s.createdAt < :end')
            ->setParameter('etablissement', $etablissement)
            ->setParameter('start', $date->setTime(0, 0))
            ->setParameter('end', $date->modify('+1 day')->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();
    }
}
