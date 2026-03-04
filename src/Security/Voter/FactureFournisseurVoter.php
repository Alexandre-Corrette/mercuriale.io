<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\FactureFournisseur;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurEtablissementRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, FactureFournisseur>
 */
class FactureFournisseurVoter extends Voter
{
    public const VIEW = 'FACTURE_VIEW';
    public const MANAGE = 'FACTURE_MANAGE';

    public function __construct(
        private readonly UtilisateurEtablissementRepository $utilisateurEtablissementRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], true)
            && $subject instanceof FactureFournisseur;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var FactureFournisseur $facture */
        $facture = $subject;
        $etablissement = $facture->getEtablissement();

        if ($etablissement === null) {
            return false;
        }

        // ROLE_ADMIN : accès à tous les établissements de son organisation
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            if ($user->getOrganisation() === null) {
                return false;
            }

            return $etablissement->getOrganisation()?->getId() === $user->getOrganisation()->getId();
        }

        // Pour les autres utilisateurs, vérifier via UtilisateurEtablissement
        $access = $this->utilisateurEtablissementRepository->findOneBy([
            'utilisateur' => $user,
            'etablissement' => $etablissement,
        ]);

        if ($access === null) {
            return false;
        }

        $role = $access->getRole();

        return match ($attribute) {
            self::VIEW => true,
            self::MANAGE => $role === 'ROLE_MANAGER',
            default => false,
        };
    }
}
