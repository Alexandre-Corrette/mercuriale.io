<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Repository\UtilisateurOrganisationRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Organisation>
 */
class OrganisationVoter extends Voter
{
    public const VIEW = 'ORG_VIEW';
    public const MANAGE = 'ORG_MANAGE';

    public function __construct(
        private readonly UtilisateurOrganisationRepository $utilisateurOrganisationRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MANAGE], true)
            && $subject instanceof Organisation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof Utilisateur) {
            return false;
        }

        /** @var Organisation $organisation */
        $organisation = $subject;

        $uo = $this->utilisateurOrganisationRepository->findOneByUtilisateurAndOrganisation($user, $organisation);

        if ($uo === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::MANAGE => $uo->getRole() === 'owner',
            default => false,
        };
    }
}
