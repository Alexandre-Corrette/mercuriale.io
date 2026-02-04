<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
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

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::IMPORT], true)
            && $subject instanceof Fournisseur;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var Fournisseur $fournisseur */
        $fournisseur = $subject;

        // Verify user has an organisation
        if ($user->getOrganisation() === null) {
            return false;
        }

        // Verify fournisseur belongs to user's organisation
        if ($fournisseur->getOrganisation()?->getId() !== $user->getOrganisation()->getId()) {
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
