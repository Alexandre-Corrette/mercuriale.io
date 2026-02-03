<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etablissement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UtilisateurEtablissement>
 */
class UtilisateurEtablissementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UtilisateurEtablissement::class);
    }

    public function findByUtilisateurAndEtablissement(Utilisateur $utilisateur, Etablissement $etablissement): ?UtilisateurEtablissement
    {
        return $this->findOneBy([
            'utilisateur' => $utilisateur,
            'etablissement' => $etablissement,
        ]);
    }

    /**
     * @return UtilisateurEtablissement[]
     */
    public function findByUtilisateur(Utilisateur $utilisateur): array
    {
        return $this->findBy(['utilisateur' => $utilisateur]);
    }
}
