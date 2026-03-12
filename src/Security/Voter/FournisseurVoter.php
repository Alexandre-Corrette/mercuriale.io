<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Repository\FournisseurRepository;
use App\Repository\OrganisationFournisseurRepository;
use App\Repository\UtilisateurOrganisationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Fournisseur>
 */
class FournisseurVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const IMPORT = 'IMPORT';
    public const CREATE = 'FOURNISSEUR_CREATE';

    public function __construct(
        private readonly OrganisationFournisseurRepository $organisationFournisseurRepository,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly UtilisateurOrganisationRepository $utilisateurOrganisationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::CREATE) {
            return true;
        }

        return \in_array($attribute, [self::VIEW, self::EDIT, self::IMPORT], true)
            && $subject instanceof Fournisseur;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        $organisation = $user->getOrganisation();
        if ($organisation === null) {
            return false;
        }

        // CREATE doesn't require a specific fournisseur — just role check
        if ($attribute === self::CREATE) {
            return \in_array('ROLE_ADMIN', $user->getRoles(), true)
                || \in_array('ROLE_MANAGER', $user->getRoles(), true);
        }

        /** @var Fournisseur $fournisseur */
        $fournisseur = $subject;

        // Check access across all user's organisations (multi-org aware for ROLE_ADMIN)
        $hasAccess = false;
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $userOrgs = $this->utilisateurOrganisationRepository->findByUtilisateur($user);
            foreach ($userOrgs as $uo) {
                $org = $uo->getOrganisation();
                if ($this->organisationFournisseurRepository->hasAccess($org, $fournisseur)
                    || $this->fournisseurRepository->hasAccessViaEtablissement($org, $fournisseur)) {
                    $hasAccess = true;
                    break;
                }
            }
        } else {
            $hasAccess = $this->organisationFournisseurRepository->hasAccess($organisation, $fournisseur)
                || $this->fournisseurRepository->hasAccessViaEtablissement($organisation, $fournisseur);
        }

        if (!$hasAccess) {
            return false;
        }

        // ROLE_ADMIN has full access
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // ROLE_MANAGER can view and import
        if (\in_array('ROLE_MANAGER', $user->getRoles(), true)) {
            return match ($attribute) {
                self::VIEW, self::IMPORT => true,
                self::EDIT => false,
                default => false,
            };
        }

        // ROLE_OPERATOR can only view
        if (\in_array('ROLE_OPERATOR', $user->getRoles(), true)) {
            return $attribute === self::VIEW;
        }

        // Default: view only for authenticated users in same org
        return $attribute === self::VIEW;
    }
}
