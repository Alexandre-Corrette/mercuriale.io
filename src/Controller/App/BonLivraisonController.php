<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\DTO\UploadResult;
use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Form\BonLivraisonUploadType;
use App\Repository\BonLivraisonRepository;
use App\Service\Upload\BonLivraisonUploadService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    #[Route('/{id}/validate', name: 'app_bl_validate', methods: ['GET', 'POST'])]
    public function validate(BonLivraison $bonLivraison, Request $request): Response
    {
        // Vérifier l'accès via le Voter
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        // TODO: Implémenter la page de validation/extraction OCR
        return $this->render('app/bon_livraison/validate.html.twig', [
            'bonLivraison' => $bonLivraison,
        ]);
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
