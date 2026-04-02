<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SignalementProduit;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurEtablissementRepository;
use App\Repository\UtilisateurOrganisationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, SignalementProduit>
 */
class SignalementProduitVoter extends Voter
{
    public const VIEW = 'SIGNALEMENT_VIEW';
    public const CREATE = 'SIGNALEMENT_CREATE';
    public const MANAGE = 'SIGNALEMENT_MANAGE';

    public function __construct(
        private readonly UtilisateurEtablissementRepository $utilisateurEtablissementRepository,
        private readonly UtilisateurOrganisationRepository $utilisateurOrganisationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CREATE, self::MANAGE], true)
            && $subject instanceof SignalementProduit;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var SignalementProduit $signalement */
        $signalement = $subject;
        $etablissement = $signalement->getEtablissement();

        if ($etablissement === null) {
            return false;
        }

        // ROLE_ADMIN : accès à tous les établissements de ses organisations
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $etabOrg = $etablissement->getOrganisation();
            if ($etabOrg === null) {
                return false;
            }

            $uo = $this->utilisateurOrganisationRepository->findOneByUtilisateurAndOrganisation($user, $etabOrg);

            return $uo !== null;
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

        // Tous les rôles peuvent voir et créer un signalement
        // Seuls MANAGER+ peuvent gérer (envoyer réclamation, créer avoir)
        return match ($attribute) {
            self::VIEW, self::CREATE => true,
            self::MANAGE => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true),
            default => false,
        };
    }
}
