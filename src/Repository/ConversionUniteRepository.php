<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConversionUnite;
use App\Entity\Unite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConversionUnite>
 */
class ConversionUniteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversionUnite::class);
    }

    public function findConversion(Unite $source, Unite $cible): ?ConversionUnite
    {
        return $this->findOneBy([
            'uniteSource' => $source,
            'uniteCible' => $cible,
        ]);
    }
}
