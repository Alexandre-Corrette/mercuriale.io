<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurOrganisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UtilisateurOrganisation>
 */
class UtilisateurOrganisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UtilisateurOrganisation::class);
    }

    /**
     * @return UtilisateurOrganisation[]
     */
    public function findByUtilisateur(Utilisateur $user): array
    {
        return $this->findBy(['utilisateur' => $user]);
    }

    public function findOneByUtilisateurAndOrganisation(Utilisateur $user, Organisation $org): ?UtilisateurOrganisation
    {
        return $this->findOneBy([
            'utilisateur' => $user,
            'organisation' => $org,
        ]);
    }
}
