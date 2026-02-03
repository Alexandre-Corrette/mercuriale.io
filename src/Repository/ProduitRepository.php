<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategorieProduit;
use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * @return Produit[]
     */
    public function findByCategorie(CategorieProduit $categorie): array
    {
        return $this->findBy(['categorie' => $categorie, 'actif' => true], ['nom' => 'ASC']);
    }

    /**
     * @return Produit[]
     */
    public function findActifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }

    /**
     * @return Produit[]
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.nom LIKE :query')
            ->orWhere('p.codeInterne LIKE :query')
            ->andWhere('p.actif = true')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
