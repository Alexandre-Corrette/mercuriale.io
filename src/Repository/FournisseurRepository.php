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
     * Retourne un QueryBuilder filtré par les accès de l'utilisateur.
     * Un utilisateur voit les fournisseurs associés à son organisation via OrganisationFournisseur.
     */
    public function createQueryBuilderForUserAccess(?Utilisateur $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('f')
            ->where('f.actif = :fournisseurActif')
            ->setParameter('fournisseurActif', true)
            ->orderBy('f.nom', 'ASC');

        if ($user === null) {
            $qb->andWhere('1 = 0');

            return $qb;
        }

        $organisation = $user->getOrganisation();
        if ($organisation !== null) {
            $qb->innerJoin('f.organisationFournisseurs', 'orgf')
                ->andWhere('orgf.organisation = :organisation')
                ->andWhere('orgf.actif = :actif')
                ->setParameter('organisation', $organisation)
                ->setParameter('actif', true);
        } else {
            $qb->andWhere('1 = 0');
        }

        return $qb;
    }
}
