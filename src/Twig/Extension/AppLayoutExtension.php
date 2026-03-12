<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Etablissement;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurOrganisation;
use App\Repository\UtilisateurOrganisationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppLayoutExtension extends AbstractExtension
{
    public const SESSION_KEY = '_selected_etablissement_id';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly UtilisateurOrganisationRepository $utilisateurOrganisationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_date', $this->getCurrentDate(...)),
            new TwigFunction('current_restaurant', $this->getCurrentRestaurant(...)),
            new TwigFunction('user_etablissements', $this->getUserEtablissements(...)),
            new TwigFunction('selected_etablissement', $this->getSelectedEtablissement(...)),
            new TwigFunction('user_organisations_with_etablissements', $this->getUserOrganisationsWithEtablissements(...)),
            new TwigFunction('has_multiple_organisations', $this->hasMultipleOrganisations(...)),
        ];
    }

    public function getCurrentDate(): string
    {
        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
        );

        return ucfirst($formatter->format(new \DateTimeImmutable()));
    }

    public function getCurrentRestaurant(): ?string
    {
        $selected = $this->getSelectedEtablissement();

        return $selected?->getNom();
    }

    /**
     * @return Etablissement[]
     */
    public function getUserEtablissements(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof Utilisateur) {
            return [];
        }

        return $user->getEtablissements();
    }

    public function getSelectedEtablissement(): ?Etablissement
    {
        $user = $this->security->getUser();

        if (!$user instanceof Utilisateur) {
            return null;
        }

        $etablissements = $user->getEtablissements();

        if (empty($etablissements)) {
            return null;
        }

        $session = $this->requestStack->getSession();
        $selectedId = $session->get(self::SESSION_KEY);

        if ($selectedId !== null) {
            foreach ($etablissements as $etab) {
                if ($etab->getId() === $selectedId) {
                    return $etab;
                }
            }
        }

        // Fallback: first etablissement
        return $etablissements[0];
    }

    /**
     * Returns organisations with their etablissements grouped for dropdown.
     *
     * @return array<int, array{organisation: \App\Entity\Organisation, etablissements: Etablissement[]}>
     */
    public function getUserOrganisationsWithEtablissements(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof Utilisateur) {
            return [];
        }

        $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);

        $result = [];
        foreach ($userOrgs as $uo) {
            $org = $uo->getOrganisation();
            $orgEtabs = [];

            // Get user's accessible etablissements in this org
            $userEtabs = $user->getEtablissements();
            foreach ($userEtabs as $etab) {
                if ($etab->getOrganisation()?->getId() === $org->getId()) {
                    $orgEtabs[] = $etab;
                }
            }

            $result[] = [
                'organisation' => $org,
                'etablissements' => $orgEtabs,
            ];
        }

        return $result;
    }

    public function hasMultipleOrganisations(): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);

        return \count($userOrgs) > 1;
    }
}
