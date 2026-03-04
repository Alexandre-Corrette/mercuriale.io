<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AvoirFournisseur;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Enum\StatutAvoir;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvoirFournisseur>
 */
class AvoirFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvoirFournisseur::class);
    }

    /**
     * @return AvoirFournisseur[]
     */
    public function findByEtablissement(Etablissement $etablissement, ?StatutAvoir $statut = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('a.demandeLe', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('a.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return AvoirFournisseur[]
     */
    public function findByFournisseurForOrganisation(Fournisseur $fournisseur, Organisation $organisation): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.etablissement', 'e')
            ->where('a.fournisseur = :fournisseur')
            ->andWhere('e.organisation = :organisation')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('organisation', $organisation)
            ->orderBy('a.demandeLe', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AvoirFournisseur[]
     */
    public function findByBonLivraison(BonLivraison $bonLivraison): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.bonLivraison = :bl')
            ->setParameter('bl', $bonLivraison)
            ->orderBy('a.demandeLe', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByStatutForOrganisation(Organisation $organisation, StatutAvoir $statut): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.etablissement', 'e')
            ->where('e.organisation = :organisation')
            ->andWhere('a.statut = :statut')
            ->setParameter('organisation', $organisation)
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return AvoirFournisseur[]
     */
    public function findRecentForOrganisation(Organisation $organisation, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.etablissement', 'e')
            ->where('e.organisation = :organisation')
            ->setParameter('organisation', $organisation)
            ->orderBy('a.demandeLe', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AvoirFournisseur[]
     */
    public function findForEtablissementWithDetails(Etablissement $etablissement, ?StatutAvoir $statut = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.fournisseur', 'f')
            ->addSelect('f')
            ->leftJoin('a.bonLivraison', 'bl')
            ->addSelect('bl')
            ->leftJoin('a.lignes', 'l')
            ->addSelect('l')
            ->where('a.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('a.demandeLe', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('a.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    public function sumImputesByFournisseurForOrganisation(Fournisseur $fournisseur, Organisation $organisation): string
    {
        $result = $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.montantHt), 0)')
            ->join('a.etablissement', 'e')
            ->where('a.fournisseur = :fournisseur')
            ->andWhere('e.organisation = :organisation')
            ->andWhere('a.statut = :statut')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('organisation', $organisation)
            ->setParameter('statut', StatutAvoir::IMPUTE)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $result;
    }
}
