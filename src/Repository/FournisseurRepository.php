<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Fournisseur>
 */
class FournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Fournisseur::class);
    }

    /**
     * @return Fournisseur[]
     */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('orgf.organisation = :organisation')
            ->andWhere('orgf.actif = :actif')
            ->andWhere('f.actif = :fournisseurActif')
            ->setParameter('organisation', $organisation)
            ->setParameter('actif', true)
            ->setParameter('fournisseurActif', true)
            ->orderBy('f.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Fournisseur[]
     */
    public function findActifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }

    /**
     * Vérifie si un fournisseur est accessible via un établissement actif de l'organisation.
     */
    public function hasAccessViaEtablissement(Organisation $organisation, Fournisseur $fournisseur): bool
    {
        $count = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->innerJoin('f.etablissements', 'etab')
            ->where('f = :fournisseur')
            ->andWhere('etab.organisation = :organisation')
            ->andWhere('etab.actif = true')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('organisation', $organisation)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Retourne un QueryBuilder filtré par les accès de l'utilisateur.
     * Un utilisateur voit les fournisseurs associés à son organisation via OrganisationFournisseur
     * OU liés directement à un établissement accessible via fournisseur_etablissement.
     */
    public function createQueryBuilderForUserAccess(?Utilisateur $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('f')
            ->select('DISTINCT f')
            ->where('f.actif = :fournisseurActif')
            ->setParameter('fournisseurActif', true)
            ->orderBy('f.nom', 'ASC');

        if ($user === null) {
            $qb->andWhere('1 = 0');

            return $qb;
        }

        $organisation = $user->getOrganisation();
        if ($organisation !== null) {
            $qb->leftJoin('f.organisationFournisseurs', 'orgf', 'WITH', 'orgf.organisation = :organisation AND orgf.actif = :actif')
                ->leftJoin('f.etablissements', 'etab', 'WITH', 'etab.organisation = :organisation AND etab.actif = true')
                ->andWhere('orgf.id IS NOT NULL OR etab.id IS NOT NULL')
                ->setParameter('organisation', $organisation)
                ->setParameter('actif', true);
        } else {
            $qb->andWhere('1 = 0');
        }

        return $qb;
    }

    public function countActiveForOrganisation(Organisation $organisation): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(DISTINCT f.id)')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('orgf.organisation = :organisation')
            ->andWhere('orgf.actif = :actif')
            ->andWhere('f.actif = :fournisseurActif')
            ->setParameter('organisation', $organisation)
            ->setParameter('actif', true)
            ->setParameter('fournisseurActif', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<array{fournisseur: Fournisseur, productCount: int}>
     */
    public function findWithProductCountForOrganisation(Organisation $organisation): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f', 'COUNT(pf.id) AS productCount')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->leftJoin('f.produitsFournisseur', 'pf', 'WITH', 'pf.actif = true')
            ->where('orgf.organisation = :organisation')
            ->andWhere('orgf.actif = :actif')
            ->andWhere('f.actif = :fournisseurActif')
            ->setParameter('organisation', $organisation)
            ->setParameter('actif', true)
            ->setParameter('fournisseurActif', true)
            ->groupBy('f.id')
            ->orderBy('f.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'fournisseur' => $row[0],
            'productCount' => (int) $row['productCount'],
        ], $rows);
    }
}
