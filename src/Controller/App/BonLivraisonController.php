<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Form\BonLivraisonUploadType;
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

        // Rate limiting: max 10 uploads par minute par utilisateur
        $limiter = $this->blUploadLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Trop de tentatives d\'upload. Veuillez patienter une minute.');
            return $this->redirectToRoute('app_bl_upload');
        }

        $form = $this->createForm(BonLivraisonUploadType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $etablissement = $form->get('etablissement')->getData();
            $file = $form->get('file')->getData();

            // Vérifier l'accès à l'établissement via le Voter
            if (!$this->isGranted('UPLOAD', $etablissement)) {
                $this->logger->warning('Tentative d\'upload non autorisée', [
                    'user_id' => $user->getId(),
                    'etablissement_id' => $etablissement->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas accès à cet établissement.');
            }

            try {
                $bonLivraison = $this->uploadService->upload($file, $etablissement, $user);

                $this->logger->info('Upload BL réussi', [
                    'user_id' => $user->getId(),
                    'etablissement_id' => $etablissement->getId(),
                    'bl_id' => $bonLivraison->getId(),
                    'file_size' => $file->getSize(),
                ]);

                $this->addFlash('success', 'Bon de livraison uploadé avec succès.');

                return $this->redirectToRoute('app_bl_validate', ['id' => $bonLivraison->getId()]);
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de l\'upload', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('app/bon_livraison/upload.html.twig', [
            'form' => $form,
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
