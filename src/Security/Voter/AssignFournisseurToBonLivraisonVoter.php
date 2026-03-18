<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\BonLivraison;
use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Repository\OrganisationFournisseurRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Prevents IDOR: a fournisseur can only be assigned to a BL if both belong
 * to the same organisation. This is critical for multi-org users (ROLE_ADMIN)
 * who have access to fournisseurs across multiple organisations.
 *
 * Usage: $this->isGranted('ASSIGN_TO_BL', [$fournisseur, $bonLivraison])
 *
 * @extends Voter<string, array{0: Fournisseur, 1: BonLivraison}>
 */
class AssignFournisseurToBonLivraisonVoter extends Voter
{
    public const ASSIGN_TO_BL = 'ASSIGN_TO_BL';

    public function __construct(
        private readonly OrganisationFournisseurRepository $organisationFournisseurRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute !== self::ASSIGN_TO_BL) {
            return false;
        }

        if (!\is_array($subject) || \count($subject) !== 2) {
            return false;
        }

        return $subject[0] instanceof Fournisseur && $subject[1] instanceof BonLivraison;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var array{0: Fournisseur, 1: BonLivraison} $subject */
        [$fournisseur, $bonLivraison] = $subject;

        // BL must have an etablissement with an organisation
        $etablissement = $bonLivraison->getEtablissement();
        if ($etablissement === null) {
            return false;
        }

        $blOrganisation = $etablissement->getOrganisation();
        if ($blOrganisation === null) {
            return false;
        }

        // Check that the fournisseur is linked to the SAME organisation as the BL
        $hasAccess = $this->organisationFournisseurRepository->hasAccess(
            $blOrganisation,
            $fournisseur,
        );

        return $hasAccess;
    }
}
