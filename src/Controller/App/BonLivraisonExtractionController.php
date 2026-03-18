<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Repository\FournisseurRepository;
use App\Service\Ocr\BonLivraisonExtractorService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * OCR extraction endpoints: extraction page, trigger extraction, poll results.
 */
#[IsGranted('ROLE_USER')]
class BonLivraisonExtractionController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonExtractorService $extractorService,
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

        try {
            $result = $this->extractorService->extract($bonLivraison);

            if (!$result->success) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => $result->warnings,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse([
                'success' => true,
                'confiance' => $result->confiance,
                'nombreLignes' => $result->getNombreLignes(),
                'produitsNonMatches' => $result->produitsNonMatches,
                'warnings' => $result->warnings,
                'tempsExtraction' => $result->tempsExtraction,
                'redirectUrl' => $this->generateUrl('app_bl_extraction', ['id' => $bonLivraison->getId()]),
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Impossible de compresser')) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => ['L\'image est trop volumineuse. Veuillez prendre une photo en qualite standard (pas HDR/ProRAW).'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Erreur extraction BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'errors' => ['Erreur lors de l\'extraction: ' . $e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
