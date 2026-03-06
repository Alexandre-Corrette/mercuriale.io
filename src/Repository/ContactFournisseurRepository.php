<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactFournisseur;
use App\Entity\Fournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactFournisseur>
 */
class ContactFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFournisseur::class);
    }

    /**
     * @return ContactFournisseur[]
     */
    public function findByFournisseur(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.fournisseur = :fournisseur')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('c.principal', 'DESC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryByFournisseur(Fournisseur $fournisseur): ?ContactFournisseur
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.fournisseur = :fournisseur')
            ->andWhere('c.principal = true')
            ->setParameter('fournisseur', $fournisseur)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
