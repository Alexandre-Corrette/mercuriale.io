<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Unite;
use App\Enum\TypeUnite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Unite>
 */
class UniteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Unite::class);
    }

    public function findByCode(string $code): ?Unite
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return Unite[]
     */
    public function findByType(TypeUnite $type): array
    {
        return $this->findBy(['type' => $type], ['ordre' => 'ASC']);
    }
}
