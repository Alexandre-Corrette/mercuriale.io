<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactFournisseur;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactFournisseur>
 */
class ContactFournisseurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactFournisseur::class);
    }

    /**
     * @return ContactFournisseur[]
     */
    public function findByFournisseur(Fournisseur $fournisseur): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.fournisseur = :fournisseur')
            ->setParameter('fournisseur', $fournisseur)
            ->orderBy('c.principal', 'DESC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findPrimaryByFournisseur(Fournisseur $fournisseur): ?ContactFournisseur
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.fournisseur = :fournisseur')
            ->andWhere('c.principal = true')
            ->setParameter('fournisseur', $fournisseur)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<int, array{fournisseur: Fournisseur, contacts: ContactFournisseur[]}>
     */
    public function findGroupedByFournisseurForOrganisation(Organisation $organisation): array
    {
        $contacts = $this->createQueryBuilder('c')
            ->join('c.fournisseur', 'f')
            ->join('f.organisationFournisseurs', 'orgf')
            ->andWhere('orgf.organisation = :org')
            ->andWhere('orgf.actif = true')
            ->setParameter('org', $organisation)
            ->orderBy('f.nom', 'ASC')
            ->addOrderBy('c.principal', 'DESC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($contacts as $contact) {
            $fournisseurId = $contact->getFournisseur()->getId();
            if (!isset($grouped[$fournisseurId])) {
                $grouped[$fournisseurId] = [
                    'fournisseur' => $contact->getFournisseur(),
                    'contacts' => [],
                ];
            }
            $grouped[$fournisseurId]['contacts'][] = $contact;
        }

        return array_values($grouped);
    }

    public function countForOrganisation(Organisation $organisation): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.fournisseur', 'f')
            ->join('f.organisationFournisseurs', 'orgf')
            ->andWhere('orgf.organisation = :org')
            ->andWhere('orgf.actif = true')
            ->setParameter('org', $organisation)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
