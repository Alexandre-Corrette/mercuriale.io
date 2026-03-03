<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppLayoutExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_date', $this->getCurrentDate(...)),
            new TwigFunction('current_restaurant', $this->getCurrentRestaurant(...)),
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
        $user = $this->security->getUser();

        if (!$user instanceof Utilisateur) {
            return null;
        }

        $etablissements = $user->getEtablissements();

        if (empty($etablissements)) {
            return null;
        }

        return $etablissements[0]->getNom();
    }
}
