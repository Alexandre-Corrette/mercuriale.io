<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Exception\NoActiveOrganisationException;
use App\Exception\OrganisationAccessRevokedException;
use App\Repository\EtablissementRepository;
use App\Repository\OrganisationRepository;
use App\Repository\UtilisateurOrganisationRepository;
use App\Twig\Extension\AppLayoutExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the active Organisation + Etablissement context for the current request.
 * Delegates to session storage, validates membership, auto-selects for mono-org users.
 */
class OrganisationContext
{
    public const SESSION_ORG_KEY = '_active_organisation_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly OrganisationRepository $organisationRepository,
        private readonly EtablissementRepository $etablissementRepository,
        private readonly UtilisateurOrganisationRepository $utilisateurOrganisationRepository,
    ) {
    }

    public function getActiveOrganisation(): Organisation
    {
        $user = $this->getUser();
        $session = $this->requestStack->getSession();
        $orgId = $session->get(self::SESSION_ORG_KEY);

        // If no org selected, try auto-select for mono-org users
        if ($orgId === null) {
            $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);

            if (\count($userOrgs) === 1) {
                $org = $userOrgs[0]->getOrganisation();
                $session->set(self::SESSION_ORG_KEY, $org->getId());

                return $org;
            }

            throw new NoActiveOrganisationException();
        }

        // Validate the org still exists and user is still a member
        $org = $this->organisationRepository->find($orgId);
        if ($org === null) {
            $session->remove(self::SESSION_ORG_KEY);
            throw new OrganisationAccessRevokedException();
        }

        $uo = $this->utilisateurOrganisationRepository->findOneByUtilisateurAndOrganisation($user, $org);
        if ($uo === null) {
            $session->remove(self::SESSION_ORG_KEY);
            $session->remove(AppLayoutExtension::SESSION_KEY);
            throw new OrganisationAccessRevokedException();
        }

        return $org;
    }

    public function getActiveEtablissement(): Etablissement
    {
        $org = $this->getActiveOrganisation();
        $user = $this->getUser();
        $session = $this->requestStack->getSession();
        $etabId = $session->get(AppLayoutExtension::SESSION_KEY);

        if ($etabId !== null) {
            $etab = $this->etablissementRepository->find($etabId);
            if ($etab !== null && $etab->getOrganisation()?->getId() === $org->getId()) {
                return $etab;
            }
            // Stale — clear and fall through
            $session->remove(AppLayoutExtension::SESSION_KEY);
        }

        // Auto-select if mono-etablissement
        $etabs = $org->getEtablissements()->filter(fn (Etablissement $e) => $e->isActif());
        if ($etabs->count() === 1) {
            $etab = $etabs->first();
            $session->set(AppLayoutExtension::SESSION_KEY, $etab->getId());

            return $etab;
        }

        // For multi-etab, pick the primary one or first
        foreach ($etabs as $etab) {
            if ($etab->isPrimary()) {
                $session->set(AppLayoutExtension::SESSION_KEY, $etab->getId());

                return $etab;
            }
        }

        if ($etabs->count() > 0) {
            $etab = $etabs->first();
            $session->set(AppLayoutExtension::SESSION_KEY, $etab->getId());

            return $etab;
        }

        throw new NoActiveOrganisationException();
    }

    public function switchContext(int $organisationId, int $etablissementId): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_ORG_KEY, $organisationId);
        $session->set(AppLayoutExtension::SESSION_KEY, $etablissementId);
    }

    public function isMultiOrganisation(): bool
    {
        $user = $this->getUser();
        $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);

        return \count($userOrgs) > 1;
    }

    /**
     * @return Organisation[]
     */
    public function getUserOrganisations(): array
    {
        $user = $this->getUser();
        $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);

        $orgs = array_map(fn ($uo) => $uo->getOrganisation(), $userOrgs);
        usort($orgs, fn (Organisation $a, Organisation $b) => $a->getNom() <=> $b->getNom());

        return $orgs;
    }

    private function getUser(): Utilisateur
    {
        $user = $this->security->getUser();
        if (!$user instanceof Utilisateur) {
            throw new NoActiveOrganisationException();
        }

        return $user;
    }
}
