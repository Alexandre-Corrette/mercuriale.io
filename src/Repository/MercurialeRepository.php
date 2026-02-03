<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\Mercuriale;
use App\Entity\ProduitFournisseur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mercuriale>
 */
class MercurialeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mercuriale::class);
    }

    public function findActivePrix(ProduitFournisseur $produitFournisseur, ?Etablissement $etablissement = null, ?\DateTimeImmutable $date = null): ?Mercuriale
    {
        $date = $date ?? new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('m')
            ->where('m.produitFournisseur = :pf')
            ->andWhere('m.dateDebut <= :date')
            ->andWhere('m.dateFin IS NULL OR m.dateFin >= :date')
            ->setParameter('pf', $produitFournisseur)
            ->setParameter('date', $date)
            ->orderBy('m.etablissement', 'DESC') // Priorité aux prix établissement
            ->setMaxResults(1);

        if ($etablissement !== null) {
            $qb->andWhere('m.etablissement = :etablissement OR m.etablissement IS NULL')
                ->setParameter('etablissement', $etablissement);
        } else {
            $qb->andWhere('m.etablissement IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return Mercuriale[]
     */
    public function findByEtablissement(?Etablissement $etablissement = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.produitFournisseur', 'pf')
            ->orderBy('pf.designationFournisseur', 'ASC');

        if ($etablissement !== null) {
            $qb->where('m.etablissement = :etablissement OR m.etablissement IS NULL')
                ->setParameter('etablissement', $etablissement);
        }

        return $qb->getQuery()->getResult();
    }
}
