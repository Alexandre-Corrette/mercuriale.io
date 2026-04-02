<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PhotoSignalement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhotoSignalement>
 */
class PhotoSignalementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoSignalement::class);
    }
}
