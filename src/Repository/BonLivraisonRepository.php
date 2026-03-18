<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BonLivraison>
 */
class BonLivraisonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonLivraison::class);
    }

    public function findDuplicate(Etablissement $etablissement, Fournisseur $fournisseur, string $numeroBl, ?int $excludeId = null): ?BonLivraison
    {
        $qb = $this->createQueryBuilder('bl')
            ->where('bl.etablissement = :etablissement')
            ->andWhere('bl.fournisseur = :fournisseur')
            ->andWhere('bl.numeroBl = :numeroBl')
            ->setParameter('etablissement', $etablissement)
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('numeroBl', $numeroBl)
            ->setMaxResults(1);

        if ($excludeId !== null) {
            $qb->andWhere('bl.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findByEtablissement(Etablissement $etablissement, ?StatutBonLivraison $statut = null): array
    {
        $qb = $this->createQueryBuilder('bl')
            ->where('bl.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->orderBy('bl.dateLivraison', 'DESC');

        if ($statut !== null) {
            $qb->andWhere('bl.statut = :statut')
                ->setParameter('statut', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findByFournisseur(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('bl')
            ->where('bl.fournisseur = :fournisseur')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('bl.dateLivraison', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('bl')
            ->orderBy('bl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findWithAnomalies(): array
    {
        return $this->findBy(['statut' => StatutBonLivraison::ANOMALIE], ['dateLivraison' => 'DESC']);
    }

    /**
     * @return BonLivraison[]
     */
    public function findValidatedForUser(Utilisateur $user, ?int $etablissementId = null, ?\DateTimeImmutable $since = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('bl')
            ->select('bl', 'l', 'a', 'f', 'e', 'u')
            ->leftJoin('bl.lignes', 'l')
            ->leftJoin('l.alertes', 'a')
            ->leftJoin('l.unite', 'u')
            ->leftJoin('bl.fournisseur', 'f')
            ->innerJoin('bl.etablissement', 'e')
            ->where('bl.statut IN (:statuts)')
            ->setParameter('statuts', [StatutBonLivraison::VALIDE, StatutBonLivraison::ANOMALIE])
            ->orderBy('bl.validatedAt', 'DESC')
            ->addOrderBy('l.ordre', 'ASC')
            ->setMaxResults($limit);

        // Scope by user access (same pattern as EtablissementRepository::createQueryBuilderForUserAccess)
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $organisation = $user->getOrganisation();
            if ($organisation !== null) {
                $qb->andWhere('e.organisation = :organisation')
                    ->setParameter('organisation', $organisation);
            } else {
                $qb->andWhere('1 = 0');
            }
        } else {
            $qb->innerJoin('e.utilisateurEtablissements', 'ue')
                ->andWhere('ue.utilisateur = :user')
                ->setParameter('user', $user);
        }

        $qb->andWhere('e.actif = :actif')
            ->setParameter('actif', true);

        if ($etablissementId !== null) {
            $qb->andWhere('bl.etablissement = :etablissementId')
                ->setParameter('etablissementId', $etablissementId);
        }

        if ($since !== null) {
            $qb->andWhere('bl.validatedAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByMonthForOrganisation(Organisation $org): int
    {
        $firstDayOfMonth = new \DateTimeImmutable('first day of this month midnight');

        return (int) $this->createQueryBuilder('bl')
            ->select('COUNT(bl.id)')
            ->innerJoin('bl.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->andWhere('bl.dateLivraison >= :firstDay')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->setParameter('firstDay', $firstDayOfMonth)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findRecentForOrganisation(Organisation $org, int $limit = 5): array
    {
        return $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'e')
            ->leftJoin('bl.fournisseur', 'f')
            ->innerJoin('bl.etablissement', 'e')
            ->where('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->orderBy('bl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findRecentByFournisseurForOrganisation(Fournisseur $fournisseur, Organisation $org, int $limit = 10): array
    {
        return $this->createQueryBuilder('bl')
            ->select('bl', 'e')
            ->innerJoin('bl.etablissement', 'e')
            ->where('bl.fournisseur = :fournisseur')
            ->andWhere('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->orderBy('bl.dateLivraison', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countBrouillonForOrganisation(Organisation $org): int
    {
        return (int) $this->createQueryBuilder('bl')
            ->select('COUNT(bl.id)')
            ->innerJoin('bl.etablissement', 'e')
            ->where('bl.statut = :statut')
            ->andWhere('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('statut', StatutBonLivraison::BROUILLON)
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return BonLivraison[]
     */
    public function findBrouillonForOrganisation(Organisation $org, int $limit = 50): array
    {
        return $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'e')
            ->leftJoin('bl.fournisseur', 'f')
            ->innerJoin('bl.etablissement', 'e')
            ->where('bl.statut = :statut')
            ->andWhere('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('statut', StatutBonLivraison::BROUILLON)
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->orderBy('bl.dateLivraison', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<array{bl: BonLivraison, alertCount: int, ligneCount: int}>
     */
    public function findByEtablissementWithAlertCount(Etablissement $etablissement, int $limit = 50): array
    {
        $rows = $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'COUNT(DISTINCT a.id) AS alertCount', 'COUNT(DISTINCT l.id) AS ligneCount')
            ->leftJoin('bl.fournisseur', 'f')
            ->leftJoin('bl.lignes', 'l')
            ->leftJoin('l.alertes', 'a')
            ->where('bl.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->groupBy('bl.id, f.id')
            ->orderBy('bl.dateLivraison', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'bl' => $row[0],
            'alertCount' => (int) $row['alertCount'],
            'ligneCount' => (int) $row['ligneCount'],
        ], $rows);
    }

    /**
     * @return array<array{bl: BonLivraison, alertCount: int, ligneCount: int}>
     */
    public function findValidatedByEtablissementWithAlertCount(Etablissement $etablissement, int $limit = 50): array
    {
        $rows = $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'COUNT(DISTINCT a.id) AS alertCount', 'COUNT(DISTINCT l.id) AS ligneCount')
            ->leftJoin('bl.fournisseur', 'f')
            ->leftJoin('bl.lignes', 'l')
            ->leftJoin('l.alertes', 'a')
            ->where('bl.etablissement = :etablissement')
            ->andWhere('bl.statut = :statut')
            ->setParameter('etablissement', $etablissement)
            ->setParameter('statut', StatutBonLivraison::VALIDE)
            ->groupBy('bl.id, f.id')
            ->orderBy('bl.dateLivraison', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'bl' => $row[0],
            'alertCount' => (int) $row['alertCount'],
            'ligneCount' => (int) $row['ligneCount'],
        ], $rows);
    }

    /**
     * @return array<array{bl: BonLivraison, alertCount: int, ligneCount: int}>
     */
    public function findAnomalieByEtablissementWithAlertCount(Etablissement $etablissement, int $limit = 50): array
    {
        $rows = $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'COUNT(DISTINCT a.id) AS alertCount', 'COUNT(DISTINCT l.id) AS ligneCount')
            ->leftJoin('bl.fournisseur', 'f')
            ->leftJoin('bl.lignes', 'l')
            ->leftJoin('l.alertes', 'a')
            ->where('bl.etablissement = :etablissement')
            ->andWhere('bl.statut = :statut')
            ->setParameter('etablissement', $etablissement)
            ->setParameter('statut', StatutBonLivraison::ANOMALIE)
            ->groupBy('bl.id, f.id')
            ->orderBy('bl.dateLivraison', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'bl' => $row[0],
            'alertCount' => (int) $row['alertCount'],
            'ligneCount' => (int) $row['ligneCount'],
        ], $rows);
    }

    /**
     * Find BLs for a given fournisseur and etablissement within a date range.
     * Used for invoice/BL rapprochement.
     *
     * @return BonLivraison[]
     */
    public function findByFournisseurAndPeriod(
        Fournisseur $fournisseur,
        Etablissement $etablissement,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        return $this->createQueryBuilder('bl')
            ->select('bl', 'l')
            ->leftJoin('bl.lignes', 'l')
            ->where('bl.fournisseur = :fournisseur')
            ->andWhere('bl.etablissement = :etablissement')
            ->andWhere('bl.dateLivraison BETWEEN :from AND :to')
            ->andWhere('bl.statut IN (:statuts)')
            ->setParameter('fournisseur', $fournisseur)
            ->setParameter('etablissement', $etablissement)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('statuts', [StatutBonLivraison::VALIDE, StatutBonLivraison::ANOMALIE])
            ->orderBy('bl.dateLivraison', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countAnomalieForOrganisation(Organisation $org): int
    {
        return (int) $this->createQueryBuilder('bl')
            ->select('COUNT(bl.id)')
            ->innerJoin('bl.etablissement', 'e')
            ->where('bl.statut = :statut')
            ->andWhere('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('statut', StatutBonLivraison::ANOMALIE)
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByEtablissement(Etablissement $etablissement): int
    {
        return (int) $this->createQueryBuilder('bl')
            ->select('COUNT(bl.id)')
            ->where('bl.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissement)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<array{bl: BonLivraison, alertCount: int, ligneCount: int}>
     */
    public function findRecentWithAlertCountForOrganisation(Organisation $org, int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('bl')
            ->select('bl', 'f', 'e', 'COUNT(DISTINCT a.id) AS alertCount', 'COUNT(DISTINCT l.id) AS ligneCount')
            ->leftJoin('bl.fournisseur', 'f')
            ->innerJoin('bl.etablissement', 'e')
            ->leftJoin('bl.lignes', 'l')
            ->leftJoin('l.alertes', 'a')
            ->where('e.organisation = :org')
            ->andWhere('e.actif = :actif')
            ->setParameter('org', $org)
            ->setParameter('actif', true)
            ->groupBy('bl.id, f.id, e.id')
            ->orderBy('bl.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (array $row) => [
            'bl' => $row[0],
            'alertCount' => (int) $row['alertCount'],
            'ligneCount' => (int) $row['ligneCount'],
        ], $rows);
    }
}
