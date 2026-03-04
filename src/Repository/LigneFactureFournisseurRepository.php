<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LigneFactureFournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LigneFactureFournisseur>
 */
class LigneFactureFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LigneFactureFournisseur::class);
    }
}
