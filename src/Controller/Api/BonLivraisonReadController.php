<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Repository\BonLivraisonRepository;
use App\Service\Upload\BonLivraisonUploadService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class BonLivraisonReadController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonRepository $bonLivraisonRepository,
        private readonly BonLivraisonUploadService $uploadService,
        private readonly RateLimiterFactory $blReadLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/bons-livraison', name: 'api_bons_livraison_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $limiter = $this->blReadLimiter->create('bl_read_' . $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(
                ['success' => false, 'error' => 'Trop de requêtes. Réessayez dans quelques instants.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $etablissementId = $request->query->getInt('etablissementId') ?: null;
        $sinceParam = $request->query->get('since');
        $limit = min($request->query->getInt('limit', 50), 200);

        $since = null;
        if ($sinceParam !== null && $sinceParam !== '') {
            try {
                $since = new \DateTimeImmutable($sinceParam);
            } catch (\Exception) {
                return $this->json(
                    ['success' => false, 'error' => 'Format de date invalide pour le paramètre since.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        try {
            $bls = $this->bonLivraisonRepository->findValidatedForUser($user, $etablissementId, $since, $limit);

            $data = array_map(fn (BonLivraison $bl) => $this->serializeBL($bl), $bls);

            return $this->json([
                'success' => true,
                'count' => count($data),
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur liste BL API', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json(
                ['success' => false, 'error' => 'Erreur serveur.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    #[Route('/bons-livraison/{id}/image', name: 'api_bons_livraison_image', methods: ['GET'])]
    public function image(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            return $this->json(
                ['success' => false, 'error' => 'Accès non autorisé.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $imagePath = $bonLivraison->getImagePath();
        if (!$imagePath) {
            throw $this->createNotFoundException('Image non trouvée.');
        }

        $fullPath = $this->uploadService->getUploadDirectory() . '/' . $imagePath;

        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Image non trouvée.');
        }

        $response = new BinaryFileResponse($fullPath);

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');
        // Images are immutable after validation
        $response->headers->set('Cache-Control', 'private, max-age=86400');

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'bon-livraison-' . $bonLivraison->getId() . '.jpg'
        );

        return $response;
    }

    private function serializeBL(BonLivraison $bl): array
    {
        $lignes = [];
        foreach ($bl->getLignes() as $ligne) {
            $alertes = [];
            foreach ($ligne->getAlertes() as $alerte) {
                $alertes[] = [
                    'id' => $alerte->getId(),
                    'typeAlerte' => $alerte->getTypeAlerte()->value,
                    'message' => $alerte->getMessage(),
                    'valeurAttendue' => $alerte->getValeurAttendue(),
                    'valeurRecue' => $alerte->getValeurRecue(),
                    'ecartPct' => $alerte->getEcartPct(),
                    'statut' => $alerte->getStatut()->value,
                ];
            }

            $lignes[] = [
                'id' => $ligne->getId(),
                'designationBl' => $ligne->getDesignationBl(),
                'codeProduitBl' => $ligne->getCodeProduitBl(),
                'quantiteLivree' => $ligne->getQuantiteLivree(),
                'prixUnitaire' => $ligne->getPrixUnitaire(),
                'totalLigne' => $ligne->getTotalLigne(),
                'unite' => $ligne->getUnite()?->getNom(),
                'statutControle' => $ligne->getStatutControle()->value,
                'ordre' => $ligne->getOrdre(),
                'alertes' => $alertes,
            ];
        }

        return [
            'id' => $bl->getId(),
            'numeroBl' => $bl->getNumeroBl(),
            'dateLivraison' => $bl->getDateLivraison()->format('c'),
            'statut' => $bl->getStatut()->value,
            'totalHt' => $bl->getTotalHt(),
            'hasImage' => $bl->getImagePath() !== null,
            'validatedAt' => $bl->getValidatedAt()?->format('c'),
            'fournisseur' => $bl->getFournisseur() ? [
                'id' => $bl->getFournisseur()->getId(),
                'nom' => $bl->getFournisseur()->getNom(),
            ] : null,
            'etablissement' => [
                'id' => $bl->getEtablissement()->getId(),
                'nom' => $bl->getEtablissement()->getNom(),
            ],
            'lignes' => $lignes,
        ];
    }
}
