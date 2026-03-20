<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Message\ProcessBonLivraisonOcrMessage;
use App\Repository\FournisseurRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class BonLivraisonExtractionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $blUploadLimiter,
    ) {
    }

    #[Route('/app/bl/{id}/extraction', name: 'app_bl_extraction', methods: ['GET'])]
    public function extraction(BonLivraison $bonLivraison, FournisseurRepository $fournisseurRepository): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $needsExtraction = $bonLivraison->getDonneesBrutes() === null;

        $fournisseurs = [];
        $organisation = $bonLivraison->getEtablissement()?->getOrganisation();
        if ($organisation !== null) {
            $fournisseurs = $fournisseurRepository->findByOrganisation($organisation);
        }

        return $this->render('app/bon_livraison/extraction.html.twig', [
            'bonLivraison' => $bonLivraison,
            'needsExtraction' => $needsExtraction,
            'extractionResult' => null,
            'fournisseurs' => $fournisseurs,
        ]);
    }

    #[Route('/app/bl/{id}/extraire', name: 'app_bl_extraire', methods: ['POST'])]
    public function extraire(BonLivraison $bonLivraison): JsonResponse
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $limiter = $this->blUploadLimiter->create('extraction_' . $user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error' => 'Trop de demandes d\'extraction. Veuillez patienter.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($bonLivraison->getStatut() === StatutBonLivraison::EN_COURS_OCR) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['Une extraction est déjà en cours pour ce bon de livraison.'],
            ], Response::HTTP_CONFLICT);
        }

        $this->messageBus->dispatch(new ProcessBonLivraisonOcrMessage($bonLivraison->getId()));

        $this->logger->info('Extraction OCR async dispatchée', [
            'bl_id' => $bonLivraison->getId(),
            'user_id' => $user->getId(),
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Extraction lancée. Le traitement est en cours.',
            'statut' => StatutBonLivraison::EN_COURS_OCR->value,
        ], Response::HTTP_ACCEPTED);
    }
}
