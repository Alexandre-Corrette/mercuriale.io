<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategorieProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorieProduit>
 */
class CategorieProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorieProduit::class);
    }

    /**
     * @return CategorieProduit[]
     */
    public function findRootCategories(): array
    {
        return $this->findBy(['parent' => null], ['ordre' => 'ASC']);
    }

    public function findByCode(string $code): ?CategorieProduit
    {
        return $this->findOneBy(['code' => $code]);
    }
}
