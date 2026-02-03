<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fournisseur;
use App\Entity\ProduitFournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProduitFournisseur>
 */
class ProduitFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProduitFournisseur::class);
    }

    public function findByFournisseurAndCode(Fournisseur $fournisseur, string $code): ?ProduitFournisseur
    {
        return $this->findOneBy([
            'fournisseur' => $fournisseur,
            'codeFournisseur' => $code,
        ]);
    }

    /**
     * @return ProduitFournisseur[]
     */
    public function findByFournisseur(Fournisseur $fournisseur): array
    {
        return $this->findBy(['fournisseur' => $fournisseur, 'actif' => true], ['designationFournisseur' => 'ASC']);
    }

    /**
     * @return ProduitFournisseur[]
     */
    public function search(Fournisseur $fournisseur, string $query): array
    {
        return $this->createQueryBuilder('pf')
            ->where('pf.fournisseur = :fournisseur')
            ->andWhere('pf.actif = true')
            ->andWhere('pf.codeFournisseur LIKE :query OR pf.designationFournisseur LIKE :query')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('pf.designationFournisseur', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
