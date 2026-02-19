<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\DTO\UploadResult;
use App\Entity\BonLivraison;
use App\Entity\LigneBonLivraison;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Form\BonLivraisonUploadType;
use App\Repository\BonLivraisonRepository;
use App\Service\Controle\ControleService;
use App\Service\Ocr\BonLivraisonExtractorService;
use App\Service\Upload\BonLivraisonUploadService;
use Doctrine\ORM\EntityManagerInterface;
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

#[Route('/app/bl')]
#[IsGranted('ROLE_USER')]
class BonLivraisonController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly BonLivraisonExtractorService $extractorService,
        private readonly ControleService $controleService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $blUploadLimiter,
    ) {
    }

    #[Route('/upload', name: 'app_bl_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(BonLivraisonUploadType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $etablissement = $form->get('etablissement')->getData();
            /** @var array<int, \Symfony\Component\HttpFoundation\File\UploadedFile> $files */
            $files = $form->get('files')->getData();

            // Vérifier l'accès à l'établissement via le Voter
            if (!$this->isGranted('UPLOAD', $etablissement)) {
                $this->logger->warning('Tentative d\'upload non autorisée', [
                    'user_id' => $user->getId(),
                    'etablissement_id' => $etablissement->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas accès à cet établissement.');
            }

            // Rate limiting: consommer autant de tokens que de fichiers
            $limiter = $this->blUploadLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(count($files))->isAccepted()) {
                $this->addFlash('error', 'Trop de tentatives d\'upload. Veuillez patienter une minute.');
                return $this->redirectToRoute('app_bl_upload');
            }

            // Upload multiple
            $result = $this->uploadService->uploadMultiple($files, $etablissement, $user);

            $this->logger->info('Upload multiple terminé', [
                'user_id' => $user->getId(),
                'etablissement_id' => $etablissement->getId(),
                'success_count' => $result->getSuccessCount(),
                'failure_count' => $result->getFailureCount(),
            ]);

            // Gestion des messages flash selon le résultat
            $this->handleUploadResultFlashes($result);

            // Redirection selon le résultat
            return $this->handleUploadResultRedirect($result);
        }

        return $this->render('app/bon_livraison/upload.html.twig', [
            'form' => $form,
        ]);
    }

    private function handleUploadResultFlashes(UploadResult $result): void
    {
        if ($result->isFullSuccess()) {
            $count = $result->getSuccessCount();
            $message = $count === 1
                ? 'Bon de livraison uploadé avec succès.'
                : sprintf('%d bons de livraison uploadés avec succès.', $count);
            $this->addFlash('success', $message);
        } elseif ($result->isFullFailure()) {
            $this->addFlash('error', 'Aucun fichier n\'a pu être uploadé.');
            foreach ($result->getFailedUploads() as $failure) {
                $this->addFlash('error', sprintf('%s : %s', $failure['filename'], $failure['error']));
            }
        } else {
            // Succès partiel
            $this->addFlash('warning', sprintf(
                '%d fichier(s) uploadé(s) sur %d.',
                $result->getSuccessCount(),
                $result->getTotalCount()
            ));
            foreach ($result->getFailedUploads() as $failure) {
                $this->addFlash('error', sprintf('%s : %s', $failure['filename'], $failure['error']));
            }
        }
    }

    private function handleUploadResultRedirect(UploadResult $result): Response
    {
        if ($result->isFullFailure()) {
            return $this->redirectToRoute('app_bl_upload');
        }

        $successfulUploads = $result->getSuccessfulUploads();

        // Un seul fichier : redirection vers la page de validation
        if (count($successfulUploads) === 1) {
            return $this->redirectToRoute('app_bl_validate', [
                'id' => $successfulUploads[0]->getId(),
            ]);
        }

        // Plusieurs fichiers : redirection vers la liste avec les IDs en session
        $ids = $result->getSuccessfulIds();
        return $this->redirectToRoute('app_bl_batch_validate', [
            'ids' => implode(',', array_filter($ids)),
        ]);
    }

    #[Route('/{id}/validate', name: 'app_bl_validate', methods: ['GET'])]
    public function validate(BonLivraison $bonLivraison): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // Rediriger vers la page d'extraction
        return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
    }

    #[Route('/{id}/extraction', name: 'app_bl_extraction', methods: ['GET'])]
    public function extraction(BonLivraison $bonLivraison): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // Si déjà extrait, afficher directement
        $extractionResult = null;
        $needsExtraction = $bonLivraison->getDonneesBrutes() === null;

        return $this->render('app/bon_livraison/extraction.html.twig', [
            'bonLivraison' => $bonLivraison,
            'needsExtraction' => $needsExtraction,
            'extractionResult' => $extractionResult,
        ]);
    }

    #[Route('/{id}/extraire', name: 'app_bl_extraire', methods: ['POST'])]
    public function extraire(BonLivraison $bonLivraison): JsonResponse
    {
        // Vérifier l'accès via le Voter (VIEW suffit pour l'extraction)
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        // Rate limiting pour l'extraction (coûteux en API)
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $limiter = $this->blUploadLimiter->create('extraction_' . $user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error' => 'Trop de demandes d\'extraction. Veuillez patienter.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            // Lancer l'extraction
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
                    'errors' => ['L\'image est trop volumineuse. Veuillez prendre une photo en qualité standard (pas HDR/ProRAW).'],
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

    #[Route('/{id}/valider', name: 'app_bl_valider', methods: ['POST'])]
    public function valider(BonLivraison $bonLivraison, Request $request): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('valider_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            // Lancer le contrôle
            $nombreAlertes = $this->controleService->controlerBonLivraison($bonLivraison);

            // Mettre à jour le statut
            if ($nombreAlertes === 0) {
                $bonLivraison->setStatut(StatutBonLivraison::VALIDE);
                $this->addFlash('success', 'Bon de livraison validé avec succès.');
            } else {
                $bonLivraison->setStatut(StatutBonLivraison::ANOMALIE);
                $this->addFlash('warning', sprintf(
                    'Bon de livraison contrôlé avec %d alerte(s) détectée(s).',
                    $nombreAlertes
                ));
            }

            // Enregistrer la validation
            $bonLivraison->setValidatedAt(new \DateTimeImmutable());
            $bonLivraison->setValidatedBy($user);

            $this->entityManager->flush();

            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur validation BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Erreur lors de la validation: ' . $e->getMessage());
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }
    }

    #[Route('/{id}/rejeter', name: 'app_bl_rejeter', methods: ['POST'])]
    public function rejeter(BonLivraison $bonLivraison, Request $request): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // Vérifier le token CSRF
        if (!$this->isCsrfTokenValid('rejeter_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        try {
            // Supprimer l'image si elle existe
            $imagePath = $bonLivraison->getImagePath();
            if ($imagePath) {
                $fullPath = $this->uploadService->getUploadDirectory() . '/' . $imagePath;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            // Supprimer le BL
            $this->entityManager->remove($bonLivraison);
            $this->entityManager->flush();

            $this->addFlash('success', 'Bon de livraison supprimé.');
            return $this->redirectToRoute('app_bl_upload');
        } catch (\Exception $e) {
            $this->logger->error('Erreur suppression BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Erreur lors de la suppression.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }
    }

    #[Route('/{id}/ligne/{ligneId}/corriger', name: 'app_bl_ligne_corriger', methods: ['POST'])]
    public function corrigerLigne(
        BonLivraison $bonLivraison,
        int $ligneId,
        Request $request,
    ): JsonResponse {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        // Trouver la ligne
        $ligne = null;
        foreach ($bonLivraison->getLignes() as $l) {
            if ($l->getId() === $ligneId) {
                $ligne = $l;
                break;
            }
        }

        if ($ligne === null) {
            return new JsonResponse(['error' => 'Ligne non trouvée'], Response::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode($request->getContent(), true);

            // Mettre à jour les champs modifiables
            if (isset($data['quantite_livree'])) {
                $ligne->setQuantiteLivree((string) $data['quantite_livree']);
            }
            if (isset($data['prix_unitaire'])) {
                $ligne->setPrixUnitaire((string) $data['prix_unitaire']);
            }
            if (isset($data['total_ligne'])) {
                $ligne->setTotalLigne((string) $data['total_ligne']);
            }

            // Recalculer le total si non fourni
            if (!isset($data['total_ligne'])) {
                $ligne->setTotalLigne($ligne->calculerTotalLigne());
            }

            $this->entityManager->flush();

            // Relancer le contrôle sur tout le BL
            $nombreAlertes = $this->controleService->controlerBonLivraison($bonLivraison);

            return new JsonResponse([
                'success' => true,
                'nombreAlertes' => $nombreAlertes,
                'ligne' => [
                    'id' => $ligne->getId(),
                    'quantiteLivree' => $ligne->getQuantiteLivree(),
                    'prixUnitaire' => $ligne->getPrixUnitaire(),
                    'totalLigne' => $ligne->getTotalLigne(),
                    'statutControle' => $ligne->getStatutControle()->value,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur correction ligne BL', [
                'bl_id' => $bonLivraison->getId(),
                'ligne_id' => $ligneId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de la correction',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/batch-validate', name: 'app_bl_batch_validate', methods: ['GET'])]
    public function batchValidate(Request $request, BonLivraisonRepository $repository): Response
    {
        $idsParam = $request->query->getString('ids', '');

        if (empty($idsParam)) {
            $this->addFlash('error', 'Aucun bon de livraison à valider.');
            return $this->redirectToRoute('app_bl_upload');
        }

        // Parser et filtrer les IDs
        $ids = array_filter(
            array_map('intval', explode(',', $idsParam)),
            fn (int $id) => $id > 0
        );

        if (empty($ids)) {
            $this->addFlash('error', 'Identifiants invalides.');
            return $this->redirectToRoute('app_bl_upload');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Récupérer les BL et vérifier les accès
        $bonsLivraison = $repository->findBy(['id' => $ids]);

        // Filtrer ceux auxquels l'utilisateur a accès
        $accessibleBls = array_filter(
            $bonsLivraison,
            fn (BonLivraison $bl) => $this->isGranted('VIEW', $bl->getEtablissement())
        );

        if (empty($accessibleBls)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ces bons de livraison.');
        }

        return $this->render('app/bon_livraison/batch_validate.html.twig', [
            'bonsLivraison' => $accessibleBls,
        ]);
    }

    #[Route('/{id}/image', name: 'app_bl_image', methods: ['GET'])]
    public function image(BonLivraison $bonLivraison): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
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

        // Headers de sécurité
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');

        // Forcer le téléchargement inline (affichage) avec un nom générique
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'bon-livraison-' . $bonLivraison->getId() . '.jpg'
        );

        return $response;
    }
}
