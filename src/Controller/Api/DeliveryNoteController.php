<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Utilisateur;
use App\Exception\InvalidFileException;
use App\Repository\EtablissementRepository;
use App\Service\Upload\BonLivraisonUploadService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class DeliveryNoteController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly EtablissementRepository $etablissementRepository,
        private readonly RateLimiterFactory $blUploadLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/delivery-notes', name: 'api_delivery_notes_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Rate limiting
        $limiter = $this->blUploadLimiter->create('bl_upload_' . $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(
                ['success' => false, 'error' => 'Trop de requêtes. Réessayez dans quelques instants.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        // Validate file
        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(
                ['success' => false, 'error' => 'Aucun fichier fourni.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Validate etablissementId
        $etablissementId = $request->request->getInt('etablissementId');
        if ($etablissementId <= 0) {
            return $this->json(
                ['success' => false, 'error' => 'Identifiant d\'établissement invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $etablissement = $this->etablissementRepository->find($etablissementId);
        if ($etablissement === null) {
            return $this->json(
                ['success' => false, 'error' => 'Établissement non trouvé.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        // Check UPLOAD access via Voter
        if (!$this->isGranted('UPLOAD', $etablissement)) {
            return $this->json(
                ['success' => false, 'error' => 'Accès non autorisé à cet établissement.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        try {
            $bonLivraison = $this->uploadService->upload($file, $etablissement, $user);

            $this->logger->info('BL créé via API sync', [
                'bl_id' => $bonLivraison->getId(),
                'etablissement_id' => $etablissement->getId(),
                'user_id' => $user->getId(),
            ]);

            return $this->json([
                'success' => true,
                'id' => $bonLivraison->getId(),
            ], Response::HTTP_CREATED);
        } catch (InvalidFileException $e) {
            return $this->json(
                ['success' => false, 'error' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur inattendue lors de l\'upload API', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);

            return $this->json(
                ['success' => false, 'error' => 'Erreur serveur lors du traitement.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
