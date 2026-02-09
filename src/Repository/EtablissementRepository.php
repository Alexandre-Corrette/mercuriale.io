<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etablissement>
 */
class EtablissementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etablissement::class);
    }

    /**
     * @return Etablissement[]
     */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->findBy(['organisation' => $organisation, 'actif' => true], ['nom' => 'ASC']);
    }

    /**
     * @return Etablissement[]
     */
    public function findActifs(): array
    {
        return $this->findBy(['actif' => true], ['nom' => 'ASC']);
    }

    /**
     * Retourne un QueryBuilder filtré par les accès de l'utilisateur.
     * Un ROLE_ADMIN voit tous les établissements de son organisation.
     * Les autres utilisateurs voient seulement ceux auxquels ils ont accès via UtilisateurEtablissement.
     */
    public function createQueryBuilderForUserAccess(?Utilisateur $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('e.nom', 'ASC');

        if ($user === null) {
            // Aucun résultat si pas d'utilisateur
            $qb->andWhere('1 = 0');
            return $qb;
        }

        // ROLE_ADMIN voit tous les établissements de son organisation
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $organisation = $user->getOrganisation();
            if ($organisation !== null) {
                $qb->andWhere('e.organisation = :organisation')
                    ->setParameter('organisation', $organisation);
            } else {
                $qb->andWhere('1 = 0');
            }
            return $qb;
        }

        // Les autres utilisateurs : filtrer par UtilisateurEtablissement
        $qb->innerJoin('e.utilisateurEtablissements', 'ue')
            ->andWhere('ue.utilisateur = :user')
            ->setParameter('user', $user);

        return $qb;
    }
}
