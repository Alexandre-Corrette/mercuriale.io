<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Enum\StatutBonLivraison;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BonLivraison>
 */
class BonLivraisonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonLivraison::class);
    }

    /**
     * @return BonLivraison[]
     */
    public function findByEtablissement(Etablissement $etablissement, ?StatutBonLivraison $statut = null): array
    {
        $qb = $this->createQueryBuilder('bl')
            ->where('bl.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('bl.dateLivraison', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('bl.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findByFournisseur(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('bl')
            ->where('bl.fournisseur = :fournisseur')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('bl.dateLivraison', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('bl')
            ->orderBy('bl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findWithAnomalies(): array
    {
        return $this->findBy(['statut' => StatutBonLivraison::ANOMALIE], ['dateLivraison' => 'DESC']);
    }
}
