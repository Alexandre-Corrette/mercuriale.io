<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlerteControle;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Enum\StatutAlerte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlerteControle>
 */
class AlerteControleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlerteControle::class);
    }

    /**
     * @return AlerteControle[]
     */
    public function findNouvelles(): array
    {
        return $this->findBy(['statut' => StatutAlerte::NOUVELLE], ['createdAt' => 'DESC']);
    }

    /**
     * @return AlerteControle[]
     */
    public function findByBonLivraison(BonLivraison $bonLivraison): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.ligneBl', 'l')
            ->where('l.bonLivraison = :bl')
            ->setParameter('bl', $bonLivraison)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AlerteControle[]
     */
    public function findByEtablissement(Etablissement $etablissement, ?StatutAlerte $statut = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.ligneBl', 'l')
            ->join('l.bonLivraison', 'bl')
            ->where('bl.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('a.createdAt', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('a.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    public function countNouvelles(): int
    {
        return $this->count(['statut' => StatutAlerte::NOUVELLE]);
    }

    public function countNonTraiteesForOrganisation(Organisation $org): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.ligneBl', 'l')
            ->join('l.bonLivraison', 'bl')
            ->innerJoin('bl.etablissement', 'e')
            ->where('a.statut = :statut')
            ->andWhere('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('statut', StatutAlerte::NOUVELLE)
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
