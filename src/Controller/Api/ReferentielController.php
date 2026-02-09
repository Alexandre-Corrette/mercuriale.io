<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Utilisateur;
use App\Repository\FournisseurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class ReferentielController extends AbstractController
{
    public function __construct(
        private readonly FournisseurRepository $fournisseurRepository,
    ) {
    }

    #[Route('/referentiels/offline', name: 'api_referentiels_offline', methods: ['GET'])]
    public function offline(): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $organisation = $user->getOrganisation();

        if (!$organisation) {
            return new JsonResponse(['error' => 'Organisation non trouvée'], 400);
        }

        // Établissements accessibles par l'utilisateur
        $etablissements = [];
        foreach ($user->getEtablissements() as $etab) {
            if ($etab->isActif()) {
                $etablissements[] = [
                    'id' => $etab->getId(),
                    'nom' => $etab->getNom(),
                    'ville' => $etab->getVille(),
                ];
            }
        }

        // Fournisseurs de l'organisation
        $fournisseurs = [];
        foreach ($this->fournisseurRepository->findByOrganisation($organisation) as $fournisseur) {
            $fournisseurs[] = [
                'id' => $fournisseur->getId(),
                'nom' => $fournisseur->getNom(),
            ];
        }

        return new JsonResponse([
            'etablissements' => $etablissements,
            'fournisseurs' => $fournisseurs,
            'generatedAt' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
