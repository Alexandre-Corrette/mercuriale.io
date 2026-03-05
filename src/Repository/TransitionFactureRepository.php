<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FactureFournisseur;
use App\Entity\TransitionFacture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransitionFacture>
 */
class TransitionFactureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransitionFacture::class);
    }

    /**
     * @return TransitionFacture[]
     */
    public function findByFacture(FactureFournisseur $facture): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.user', 'u')
            ->addSelect('u')
            ->where('t.facture = :facture')
            ->setParameter('facture', $facture)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
