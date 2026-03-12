<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Abonnement;
use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\PlanType;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use App\Entity\UtilisateurOrganisation;
use App\Exception\DuplicateSirenException;
use App\Exception\DuplicateSiretException;
use App\Repository\EtablissementRepository;
use App\Repository\OrganisationRepository;
use Doctrine\ORM\EntityManagerInterface;

class OnboardingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganisationRepository $organisationRepository,
        private readonly EtablissementRepository $etablissementRepository,
    ) {
    }

    /**
     * @param array{adresse?: string, codePostal?: string, ville?: string} $adresseData
     *
     * @return array{0: Organisation, 1: Etablissement}
     */
    public function createOrganisationWithEtablissement(
        string $nom,
        ?string $siren,
        ?string $siret,
        array $adresseData = [],
    ): array {
        if ($siren !== null && $siren !== '') {
            $existing = $this->organisationRepository->findBySiren($siren);
            if ($existing !== null) {
                throw new DuplicateSirenException($siren);
            }
        }

        if ($siret !== null && $siret !== '') {
            $existing = $this->etablissementRepository->findBySiret($siret);
            if ($existing !== null) {
                throw new DuplicateSiretException($siret);
            }
        }

        $organisation = new Organisation();
        $organisation->setNom($nom);
        if ($siren !== null && $siren !== '') {
            $organisation->setSiren($siren);
        }
        if ($siret !== null && $siret !== '') {
            $organisation->setSiret($siret);
        }
        $organisation->setTrialEndsAt(new \DateTimeImmutable('+14 days'));
        $this->em->persist($organisation);

        // Create Abonnement (trial, 14 days)
        $abonnement = new Abonnement();
        $abonnement->setOrganisation($organisation);
        $abonnement->setPlan(PlanType::TRIAL);
        $abonnement->setStartsAt(new \DateTimeImmutable());
        $abonnement->setEndsAt(new \DateTimeImmutable('+14 days'));
        $this->em->persist($abonnement);

        $etablissement = new Etablissement();
        $etablissement->setOrganisation($organisation);
        $etablissement->setNom($nom);
        $etablissement->setIsPrimary(true);
        if ($siret !== null && $siret !== '') {
            $etablissement->setSiret($siret);
        }
        if (isset($adresseData['adresse']) && $adresseData['adresse'] !== '') {
            $etablissement->setAdresse($adresseData['adresse']);
        }
        if (isset($adresseData['codePostal']) && $adresseData['codePostal'] !== '') {
            $etablissement->setCodePostal($adresseData['codePostal']);
        }
        if (isset($adresseData['ville']) && $adresseData['ville'] !== '') {
            $etablissement->setVille($adresseData['ville']);
        }
        $this->em->persist($etablissement);

        return [$organisation, $etablissement];
    }

    /**
     * @param array{adresse?: string, codePostal?: string, ville?: string} $adresseData
     */
    public function addEtablissementToOrganisation(
        Organisation $org,
        string $nom,
        ?string $siret,
        array $adresseData = [],
    ): Etablissement {
        if ($siret !== null && $siret !== '') {
            $existing = $this->etablissementRepository->findBySiret($siret);
            if ($existing !== null) {
                throw new DuplicateSiretException($siret);
            }
        }

        $etablissement = new Etablissement();
        $etablissement->setOrganisation($org);
        $etablissement->setNom($nom);
        $etablissement->setIsPrimary(false);
        if ($siret !== null && $siret !== '') {
            $etablissement->setSiret($siret);
        }
        if (isset($adresseData['adresse']) && $adresseData['adresse'] !== '') {
            $etablissement->setAdresse($adresseData['adresse']);
        }
        if (isset($adresseData['codePostal']) && $adresseData['codePostal'] !== '') {
            $etablissement->setCodePostal($adresseData['codePostal']);
        }
        if (isset($adresseData['ville']) && $adresseData['ville'] !== '') {
            $etablissement->setVille($adresseData['ville']);
        }
        $this->em->persist($etablissement);

        return $etablissement;
    }

    public function linkUserToOrganisation(
        Utilisateur $user,
        Organisation $org,
        string $role = 'owner',
    ): UtilisateurOrganisation {
        $uo = new UtilisateurOrganisation();
        $uo->setUtilisateur($user);
        $uo->setOrganisation($org);
        $uo->setRole($role);
        $this->em->persist($uo);

        return $uo;
    }

    public function linkUserToEtablissement(
        Utilisateur $user,
        Etablissement $etab,
        string $role = 'ROLE_GERANT',
    ): UtilisateurEtablissement {
        $ue = new UtilisateurEtablissement();
        $ue->setUtilisateur($user);
        $ue->setEtablissement($etab);
        $ue->setRole($role);
        $this->em->persist($ue);

        return $ue;
    }
}
