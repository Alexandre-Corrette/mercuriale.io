<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Exception\InvalidFileException;
use App\Repository\BonLivraisonRepository;
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

#[Route('/api/bons-livraison')]
#[IsGranted('ROLE_USER')]
class BonLivraisonApiController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonRepository $bonLivraisonRepository,
        private readonly BonLivraisonUploadService $uploadService,
        private readonly EtablissementRepository $etablissementRepository,
        private readonly RateLimiterFactory $blReadLimiter,
        private readonly RateLimiterFactory $blUploadLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'api_bons_livraison_list', methods: ['GET'])]
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

    #[Route('', name: 'api_bons_livraison_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $limiter = $this->blUploadLimiter->create('bl_upload_' . $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(
                ['success' => false, 'error' => 'Trop de requêtes. Réessayez dans quelques instants.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(
                ['success' => false, 'error' => 'Aucun fichier fourni.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

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
