<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BonLivraison;
use App\Entity\LigneBonLivraison;
use App\Enum\StatutControle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LigneBonLivraison>
 */
class LigneBonLivraisonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LigneBonLivraison::class);
    }

    /**
     * @return LigneBonLivraison[]
     */
    public function findByBonLivraison(BonLivraison $bonLivraison): array
    {
        return $this->findBy(['bonLivraison' => $bonLivraison], ['ordre' => 'ASC']);
    }

    /**
     * @return LigneBonLivraison[]
     */
    public function findWithEcarts(BonLivraison $bonLivraison): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.bonLivraison = :bl')
            ->andWhere('l.statutControle != :ok')
            ->andWhere('l.statutControle != :nonControle')
            ->setParameter('bl', $bonLivraison)
            ->setParameter('ok', StatutControle::OK)
            ->setParameter('nonControle', StatutControle::NON_CONTROLE)
            ->orderBy('l.ordre', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
