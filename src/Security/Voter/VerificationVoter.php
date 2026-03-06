<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, null>
 */
class VerificationVoter extends Voter
{
    public const TRIAL_FEATURE = 'TRIAL_FEATURE';
    public const VERIFIED_FEATURE = 'VERIFIED_FEATURE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::TRIAL_FEATURE, self::VERIFIED_FEATURE], true)
            && $subject === null;
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

        // Verified users have full access
        if ($organisation->isVerified()) {
            return true;
        }

        return match ($attribute) {
            // Trial features (BL, mercuriale, produits, fournisseurs, profil) — OK if trial still active
            self::TRIAL_FEATURE => $organisation->isTrialActive(),
            // Verified features (avoirs, factures, export) — only if verified
            self::VERIFIED_FEATURE => false,
            default => false,
        };
    }
}
