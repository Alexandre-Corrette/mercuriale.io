<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Entity\Organisation;
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
    public function findUnmatchedForEtablissement(Etablissement $etablissement): array
    {
        return $this->createQueryBuilder('f')
            ->leftJoin('f.fournisseur', 'fo')
            ->addSelect('fo')
            ->where('f.etablissement = :etablissement')
            ->andWhere('f.statut = :statut')
            ->andWhere('f.bonLivraison IS NULL')
            ->andWhere('f.fournisseur IS NOT NULL')
            ->setParameter('etablissement', $etablissement)
            ->setParameter('statut', StatutFacture::RECUE)
            ->orderBy('f.dateEmission', 'DESC')
            ->getQuery()
            ->getResult();
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

    /**
     * @return FactureFournisseur[]
     */
    public function findReceivedForOrganisation(?Organisation $organisation, int $limit = 50): array
    {
        if ($organisation === null) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->leftJoin('f.fournisseur', 'fo')
            ->addSelect('fo')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut IN (:statuts)')
            ->setParameter('org', $organisation)
            ->setParameter('statuts', [StatutFacture::RECUE, StatutFacture::RAPPROCHEE])
            ->orderBy('f.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return FactureFournisseur[]
     */
    public function findToBePaidForOrganisation(?Organisation $organisation, int $limit = 50): array
    {
        if ($organisation === null) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->leftJoin('f.fournisseur', 'fo')
            ->addSelect('fo')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut = :statut')
            ->setParameter('org', $organisation)
            ->setParameter('statut', StatutFacture::ACCEPTEE)
            ->orderBy('f.dateEcheance', 'ASC')
            ->addOrderBy('f.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return FactureFournisseur[]
     */
    public function findArchivedForOrganisation(?Organisation $organisation, int $limit = 50, int $offset = 0): array
    {
        if ($organisation === null) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->leftJoin('f.fournisseur', 'fo')
            ->addSelect('fo')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut IN (:statuts)')
            ->setParameter('org', $organisation)
            ->setParameter('statuts', [StatutFacture::PAYEE, StatutFacture::REFUSEE, StatutFacture::CONTESTEE])
            ->orderBy('f.updatedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countArchivedForOrganisation(?Organisation $organisation): int
    {
        if ($organisation === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut IN (:statuts)')
            ->setParameter('org', $organisation)
            ->setParameter('statuts', [StatutFacture::PAYEE, StatutFacture::REFUSEE, StatutFacture::CONTESTEE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatutForOrganisation(?Organisation $organisation, StatutFacture $statut): int
    {
        if ($organisation === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut = :statut')
            ->setParameter('org', $organisation)
            ->setParameter('statut', $statut)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countReceivedForOrganisation(?Organisation $organisation): int
    {
        if ($organisation === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut IN (:statuts)')
            ->setParameter('org', $organisation)
            ->setParameter('statuts', [StatutFacture::RECUE, StatutFacture::RAPPROCHEE])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOverdueForOrganisation(?Organisation $organisation): int
    {
        if ($organisation === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->join('f.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('f.statut = :statut')
            ->andWhere('f.dateEcheance < :today')
            ->setParameter('org', $organisation)
            ->setParameter('statut', StatutFacture::ACCEPTEE)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{taux: string, montant: string}[]
     */
    public function sumVatByRateForOrganisation(?Organisation $organisation, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($organisation === null) {
            return [];
        }

        return $this->getEntityManager()->createQuery(
            'SELECT l.tauxTva as taux, SUM(l.montantLigne * l.tauxTva / 100) as montantTva, SUM(l.montantLigne) as montantHt
             FROM App\Entity\LigneFactureFournisseur l
             JOIN l.facture f
             JOIN f.etablissement e
             WHERE e.organisation = :org
             AND f.statut IN (:statuts)
             AND f.dateEmission >= :from
             AND f.dateEmission <= :to
             AND l.tauxTva IS NOT NULL
             GROUP BY l.tauxTva
             ORDER BY l.tauxTva ASC'
        )
            ->setParameter('org', $organisation)
            ->setParameter('statuts', [StatutFacture::ACCEPTEE, StatutFacture::PAYEE])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getResult();
    }

    /**
     * @return FactureFournisseur[]
     */
    public function findDueForReminder(\DateTimeImmutable $dueDate, int $limit = 100): array
    {
        return $this->createQueryBuilder('f')
            ->join('f.etablissement', 'e')
            ->where('f.statut = :statut')
            ->andWhere('f.dateEcheance = :dueDate')
            ->andWhere('(f.lastReminderSentAt IS NULL OR f.lastReminderSentAt < :cutoff)')
            ->setParameter('statut', StatutFacture::ACCEPTEE)
            ->setParameter('dueDate', $dueDate)
            ->setParameter('cutoff', new \DateTimeImmutable('-23 hours'))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
