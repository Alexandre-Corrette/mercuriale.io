<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Enum\StatutFacture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FactureFournisseur>
 */
class FactureFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FactureFournisseur::class);
    }

    public function findByExternalId(string $externalId): ?FactureFournisseur
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    /**
     * @return FactureFournisseur[]
     */
    public function findForEtablissement(Etablissement $etablissement, ?StatutFacture $statut = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->leftJoin('f.fournisseur', 'fo')
            ->addSelect('fo')
            ->where('f.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('f.dateEmission', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('f.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }
}
