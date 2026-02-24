<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategorieProduit;
use App\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorieProduit>
 */
class CategorieProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorieProduit::class);
    }

    /**
     * @return CategorieProduit[]
     */
    public function findRootCategories(): array
    {
        return $this->findBy(['parent' => null], ['ordre' => 'ASC']);
    }

    public function findByCode(string $code): ?CategorieProduit
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @return array<array{categorie: CategorieProduit, productCount: int}>
     */
    public function findWithProductCountForOrganisation(Organisation $org): array
    {
        $em = $this->getEntityManager();

        $rows = $em->createQueryBuilder()
            ->select('cat', 'COUNT(pf.id) AS productCount')
            ->from(CategorieProduit::class, 'cat')
            ->innerJoin('App\Entity\Produit', 'p', 'WITH', 'p.categorie = cat')
            ->innerJoin('p.produitsFournisseur', 'pf')
            ->innerJoin('pf.fournisseur', 'f')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('orgf.organisation = :org')
            ->andWhere('orgf.actif = :actif')
            ->andWhere('pf.actif = :pfActif')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->setParameter('pfActif', true)
            ->groupBy('cat.id')
            ->having('COUNT(pf.id) > 0')
            ->orderBy('cat.ordre', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'categorie' => $row[0],
            'productCount' => (int) $row['productCount'],
        ], $rows);
    }
}
