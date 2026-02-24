<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Etablissement;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurEtablissementRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Etablissement>
 */
class EtablissementVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const UPLOAD = 'UPLOAD';
    public const MANAGE = 'MANAGE';

    public function __construct(
        private readonly UtilisateurEtablissementRepository $utilisateurEtablissementRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPLOAD, self::MANAGE], true)
            && $subject instanceof Etablissement;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var Etablissement $etablissement */
        $etablissement = $subject;

        // ROLE_ADMIN a accès à tous les établissements de son organisation
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            // Vérifier que l'établissement appartient à la même organisation
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
            self::VIEW => true, // Si l'accès existe, l'utilisateur peut voir
            self::UPLOAD => in_array($role, ['ROLE_MANAGER', 'ROLE_OPERATOR'], true),
            self::MANAGE => $role === 'ROLE_MANAGER',
            default => false,
        };
    }
}
