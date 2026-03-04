<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LigneAvoir;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LigneAvoir>
 */
class LigneAvoirRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LigneAvoir::class);
    }
}
