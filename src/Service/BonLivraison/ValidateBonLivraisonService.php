<?php

declare(strict_types=1);

namespace App\Service\BonLivraison;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Service\Controle\ControleService;
use Doctrine\ORM\EntityManagerInterface;

class ValidateBonLivraisonService
{
    public function __construct(
        private readonly ControleService $controleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Run contrôle on a BL, set status accordingly, and return the alert count.
     */
    public function validate(BonLivraison $bonLivraison, Utilisateur $user): int
    {
        $nombreAlertes = $this->controleService->controlerBonLivraison($bonLivraison);

        if ($nombreAlertes === 0) {
            $bonLivraison->setStatut(StatutBonLivraison::VALIDE);
        } else {
            $bonLivraison->setStatut(StatutBonLivraison::ANOMALIE);
        }

        $bonLivraison->setValidatedAt(new \DateTimeImmutable());
        $bonLivraison->setValidatedBy($user);

        $this->entityManager->flush();

        return $nombreAlertes;
    }
}
