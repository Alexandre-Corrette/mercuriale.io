<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\OrganisationFournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganisationFournisseur>
 */
class OrganisationFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganisationFournisseur::class);
    }

    /**
     * @return OrganisationFournisseur[]
     */
    public function findByOrganisation(Organisation $organisation, bool $actifOnly = true): array
    {
        $qb = $this->createQueryBuilder('orgf')
            ->join('orgf.fournisseur', 'f')
            ->where('orgf.organisation = :organisation')
            ->setParameter('organisation', $organisation)
            ->orderBy('f.nom', 'ASC');

        if ($actifOnly) {
            $qb->andWhere('orgf.actif = :actif')
                ->setParameter('actif', true);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return OrganisationFournisseur[]
     */
    public function findByFournisseur(Fournisseur $fournisseur, bool $actifOnly = true): array
    {
        $qb = $this->createQueryBuilder('orgf')
            ->join('orgf.organisation', 'o')
            ->where('orgf.fournisseur = :fournisseur')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('o.nom', 'ASC');

        if ($actifOnly) {
            $qb->andWhere('orgf.actif = :actif')
                ->setParameter('actif', true);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByOrganisationAndFournisseur(
        Organisation $organisation,
        Fournisseur $fournisseur,
        bool $actifOnly = true
    ): ?OrganisationFournisseur {
        $qb = $this->createQueryBuilder('orgf')
            ->where('orgf.organisation = :organisation')
            ->andWhere('orgf.fournisseur = :fournisseur')
            ->setParameter('organisation', $organisation)
            ->setParameter('fournisseur', $fournisseur);

        if ($actifOnly) {
            $qb->andWhere('orgf.actif = :actif')
                ->setParameter('actif', true);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function hasAccess(Organisation $organisation, Fournisseur $fournisseur): bool
    {
        return $this->findOneByOrganisationAndFournisseur($organisation, $fournisseur, true) !== null;
    }
}
