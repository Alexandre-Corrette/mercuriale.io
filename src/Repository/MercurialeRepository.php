<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\Mercuriale;
use App\Entity\Organisation;
use App\Entity\ProduitFournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mercuriale>
 */
class MercurialeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mercuriale::class);
    }

    public function findActivePrix(ProduitFournisseur $produitFournisseur, ?Etablissement $etablissement = null, ?\DateTimeImmutable $date = null): ?Mercuriale
    {
        $date = $date ?? new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('m')
            ->where('m.produitFournisseur = :pf')
            ->andWhere('m.dateDebut <= :date')
            ->andWhere('m.dateFin IS NULL OR m.dateFin >= :date')
            ->setParameter('pf', $produitFournisseur)
            ->setParameter('date', $date)
            ->orderBy('m.etablissement', 'DESC') // Priorité aux prix établissement
            ->setMaxResults(1);

        if ($etablissement !== null) {
            $qb->andWhere('m.etablissement = :etablissement OR m.etablissement IS NULL')
                ->setParameter('etablissement', $etablissement);
        } else {
            $qb->andWhere('m.etablissement IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Mercuriale[]
     */
    public function findByEtablissement(?Etablissement $etablissement = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.produitFournisseur', 'pf')
            ->orderBy('pf.designationFournisseur', 'ASC');

        if ($etablissement !== null) {
            $qb->where('m.etablissement = :etablissement OR m.etablissement IS NULL')
                ->setParameter('etablissement', $etablissement);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve le prix mercuriale valide pour un produit fournisseur.
     * Si etablissement est null, cherche uniquement les prix groupe.
     */
    public function findPrixValide(
        ProduitFournisseur $produitFournisseur,
        ?Etablissement $etablissement,
        \DateTimeInterface $date,
    ): ?Mercuriale {
        $qb = $this->createQueryBuilder('m')
            ->where('m.produitFournisseur = :pf')
            ->andWhere('m.dateDebut <= :date')
            ->andWhere('m.dateFin IS NULL OR m.dateFin >= :date')
            ->setParameter('pf', $produitFournisseur)
            ->setParameter('date', $date)
            ->setMaxResults(1);

        if ($etablissement !== null) {
            $qb->andWhere('m.etablissement = :etablissement')
                ->setParameter('etablissement', $etablissement);
        } else {
            $qb->andWhere('m.etablissement IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function countActiveForOrganisation(Organisation $org): int
    {
        $now = new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.produitFournisseur', 'pf')
            ->join('pf.fournisseur', 'f')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('orgf.organisation = :org')
            ->andWhere('orgf.actif = :actif')
            ->andWhere('m.dateDebut <= :now')
            ->andWhere('m.dateFin IS NULL OR m.dateFin >= :now')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Mercuriale[]
     */
    public function findRecentForOrganisation(Organisation $org, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'pf', 'f')
            ->join('m.produitFournisseur', 'pf')
            ->join('pf.fournisseur', 'f')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('orgf.organisation = :org')
            ->andWhere('orgf.actif = :actif')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->orderBy('m.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
