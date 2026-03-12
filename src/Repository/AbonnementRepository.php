<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Abonnement;
use App\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Abonnement>
 */
class AbonnementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Abonnement::class);
    }

    public function findByOrganisation(Organisation $org): ?Abonnement
    {
        return $this->findOneBy(['organisation' => $org]);
    }
}
