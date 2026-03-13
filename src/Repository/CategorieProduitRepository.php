<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategorieProduit;
use App\Entity\Organisation;
use App\Entity\Unite;
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

    /**
     * @return array<array{categorie: CategorieProduit, productCount: int}>
     */
    public function findAllWithProductCount(Organisation $org): array
    {
        $em = $this->getEntityManager();

        // Categories with products
        $withProducts = $this->findWithProductCountForOrganisation($org);
        $foundIds = array_map(fn (array $row) => $row['categorie']->getId(), $withProducts);

        // All categories (including empty ones)
        $allCategories = $this->findBy([], ['ordre' => 'ASC']);

        $result = $withProducts;
        foreach ($allCategories as $cat) {
            if (!in_array($cat->getId(), $foundIds, true)) {
                $result[] = ['categorie' => $cat, 'productCount' => 0];
            }
        }

        return $result;
    }

    /**
     * @return Unite[]
     */
    public function findDistinctUnitsForCategory(CategorieProduit $categorie, Organisation $org): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(Unite::class, 'u')
            ->innerJoin('App\Entity\Produit', 'p', 'WITH', 'p.uniteBase = u')
            ->innerJoin('p.produitsFournisseur', 'pf')
            ->innerJoin('pf.fournisseur', 'f')
            ->innerJoin('f.organisationFournisseurs', 'orgf')
            ->where('p.categorie = :cat')
            ->andWhere('orgf.organisation = :org')
            ->andWhere('orgf.actif = true')
            ->andWhere('pf.actif = true')
            ->setParameter('cat', $categorie)
            ->setParameter('org', $org)
            ->orderBy('u.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
