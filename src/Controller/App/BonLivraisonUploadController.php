<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\DTO\UploadResult;
use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Form\BonLivraisonUploadType;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use App\Service\BonLivraison\RejectBonLivraisonService;
use App\Service\BonLivraison\ValidateBonLivraisonService;
use App\Service\Controle\ControleService;
use App\Service\Upload\BonLivraisonUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EtablissementVoter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BL upload, validation, rejection, line correction, fournisseur assignment, batch validate.
 */
#[IsGranted('ROLE_USER')]
class BonLivraisonUploadController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly ValidateBonLivraisonService $validateService,
        private readonly RejectBonLivraisonService $rejectService,
        private readonly ControleService $controleService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $blUploadLimiter,
    ) {
    }

    #[Route('/app/bl/upload', name: 'app_bl_upload', methods: ['GET', 'POST'])]
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

            if (!$this->isGranted(EtablissementVoter::UPLOAD, $etablissement)) {
                $this->logger->warning('Tentative d\'upload non autorisee', [
                    'user_id' => $user->getId(),
                    'etablissement_id' => $etablissement->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas acces a cet etablissement.');
            }

            $limiter = $this->blUploadLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(count($files))->isAccepted()) {
                $this->addFlash('error', 'Trop de tentatives d\'upload. Veuillez patienter une minute.');

                return $this->redirectToRoute('app_bl_upload');
            }

            $result = $this->uploadService->uploadMultiple($files, $etablissement, $user);

            $this->logger->info('Upload multiple termine', [
                'user_id' => $user->getId(),
                'etablissement_id' => $etablissement->getId(),
                'success_count' => $result->getSuccessCount(),
                'failure_count' => $result->getFailureCount(),
            ]);

            $this->handleUploadResultFlashes($result);

            return $this->handleUploadResultRedirect($result);
        }

        return $this->render('app/bon_livraison/upload.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/app/bl/{id}/validate', name: 'app_bl_validate', methods: ['GET'])]
    public function validate(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted(EtablissementVoter::VIEW, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
    }

    #[Route('/app/bl/{id}/valider', name: 'app_bl_valider', methods: ['POST'])]
    public function valider(BonLivraison $bonLivraison, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('valider_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            $nombreAlertes = $this->validateService->validate($bonLivraison, $user);

            if ($nombreAlertes === 0) {
                $this->addFlash('success', 'Bon de livraison valide avec succes.');
            } else {
                $this->addFlash('warning', sprintf(
                    'Bon de livraison controle avec %d alerte(s) detectee(s).',
                    $nombreAlertes
                ));
            }

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

    #[Route('/app/bl/{id}/rejeter', name: 'app_bl_rejeter', methods: ['POST'])]
    public function rejeter(BonLivraison $bonLivraison, Request $request): Response
    {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('rejeter_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        try {
            $this->rejectService->reject($bonLivraison);
            $this->addFlash('success', 'Bon de livraison supprime.');

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

    #[Route('/app/bl/{id}/ligne/{ligneId}/corriger', name: 'app_bl_ligne_corriger', methods: ['POST'])]
    public function corrigerLigne(
        BonLivraison $bonLivraison,
        int $ligneId,
        Request $request,
    ): JsonResponse {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $ligne = null;
        foreach ($bonLivraison->getLignes() as $l) {
            if ($l->getId() === $ligneId) {
                $ligne = $l;
                break;
            }
        }

        if ($ligne === null) {
            return new JsonResponse(['error' => 'Ligne non trouvee'], Response::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode($request->getContent(), true);

            if (isset($data['quantite_livree'])) {
                $ligne->setQuantiteLivree((string) $data['quantite_livree']);
            }
            if (isset($data['prix_unitaire'])) {
                $ligne->setPrixUnitaire((string) $data['prix_unitaire']);
            }
            if (isset($data['total_ligne'])) {
                $ligne->setTotalLigne((string) $data['total_ligne']);
            }

            if (!isset($data['total_ligne'])) {
                $ligne->setTotalLigne($ligne->calculerTotalLigne());
            }

            $this->entityManager->flush();

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

    #[Route('/app/bl/{id}/set-fournisseur', name: 'app_bl_set_fournisseur', methods: ['POST'])]
    public function setFournisseur(
        BonLivraison $bonLivraison,
        Request $request,
        FournisseurRepository $fournisseurRepository,
    ): JsonResponse {
        if (!$this->isGranted(EtablissementVoter::MANAGE, $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $fournisseurId = $data['fournisseur_id'] ?? null;

        if ($fournisseurId === null) {
            $bonLivraison->setFournisseur(null);
            $this->entityManager->flush();

            return new JsonResponse(['success' => true, 'fournisseur' => null]);
        }

        $fournisseur = $fournisseurRepository->find((int) $fournisseurId);
        if ($fournisseur === null) {
            return new JsonResponse(['error' => 'Fournisseur non trouve'], Response::HTTP_NOT_FOUND);
        }

        // Verify fournisseur belongs to same organisation as the BL (IDOR protection)
        if (!$this->isGranted('ASSIGN_TO_BL', [$fournisseur, $bonLivraison])) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $bonLivraison->setFournisseur($fournisseur);
        $this->entityManager->flush();

        $this->logger->info('Fournisseur BL corrige manuellement', [
            'bl_id' => $bonLivraison->getId(),
            'fournisseur_id' => $fournisseur->getId(),
            'fournisseur_nom' => $fournisseur->getNom(),
        ]);

        return new JsonResponse([
            'success' => true,
            'fournisseur' => [
                'id' => $fournisseur->getId(),
                'nom' => $fournisseur->getNom(),
            ],
        ]);
    }

    #[Route('/app/bl/batch-validate', name: 'app_bl_batch_validate', methods: ['GET'])]
    public function batchValidate(Request $request, BonLivraisonRepository $repository): Response
    {
        $idsParam = $request->query->getString('ids', '');

        if (empty($idsParam)) {
            $this->addFlash('error', 'Aucun bon de livraison a valider.');

            return $this->redirectToRoute('app_bl_upload');
        }

        $ids = array_filter(
            array_map('intval', explode(',', $idsParam)),
            fn (int $id) => $id > 0
        );

        if (empty($ids)) {
            $this->addFlash('error', 'Identifiants invalides.');

            return $this->redirectToRoute('app_bl_upload');
        }

        $bonsLivraison = $repository->findBy(['id' => $ids]);

        $accessibleBls = array_filter(
            $bonsLivraison,
            fn (BonLivraison $bl) => $this->isGranted(EtablissementVoter::VIEW, $bl->getEtablissement())
        );

        if (empty($accessibleBls)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas acces a ces bons de livraison.');
        }

        return $this->render('app/bon_livraison/batch_validate.html.twig', [
            'bonsLivraison' => $accessibleBls,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function handleUploadResultFlashes(UploadResult $result): void
    {
        if ($result->isFullSuccess()) {
            $count = $result->getSuccessCount();
            $message = $count === 1
                ? 'Bon de livraison uploade avec succes.'
                : sprintf('%d bons de livraison uploades avec succes.', $count);
            $this->addFlash('success', $message);
        } elseif ($result->isFullFailure()) {
            $this->addFlash('error', 'Aucun fichier n\'a pu etre uploade.');
            foreach ($result->getFailedUploads() as $failure) {
                $this->addFlash('error', sprintf('%s : %s', $failure['filename'], $failure['error']));
            }
        } else {
            $this->addFlash('warning', sprintf(
                '%d fichier(s) uploade(s) sur %d.',
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

        if (count($successfulUploads) === 1) {
            return $this->redirectToRoute('app_bl_validate', [
                'id' => $successfulUploads[0]->getId(),
            ]);
        }

        $ids = $result->getSuccessfulIds();

        return $this->redirectToRoute('app_bl_batch_validate', [
            'ids' => implode(',', array_filter($ids)),
        ]);
    }
}
