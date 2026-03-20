<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BonLivraison;
use App\Service\Upload\BonLivraisonUploadService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BonLivraisonImageService
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build a secure BinaryFileResponse for a BL image.
     *
     * @throws NotFoundHttpException if the image path is missing or the file does not exist
     */
    public function getImageResponse(BonLivraison $bonLivraison): BinaryFileResponse
    {
        $imagePath = $bonLivraison->getImagePath();
        if (!$imagePath) {
            throw new NotFoundHttpException('Image non trouvee.');
        }

        $fullPath = $this->uploadService->getUploadDirectory() . '/' . $imagePath;

        if (!file_exists($fullPath)) {
            throw new NotFoundHttpException('Image non trouvee.');
        }

        $response = new BinaryFileResponse($fullPath);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'bon-livraison-' . $bonLivraison->getId() . '.jpg'
        );

        return $response;
    }

    public function deleteImage(BonLivraison $bonLivraison): void
    {
        $imagePath = $bonLivraison->getImagePath();
        if ($imagePath === null) {
            $this->logger->warning('deleteImage appelé sur un BL sans image', [
                'bl_id' => $bonLivraison->getId(),
            ]);
            return;
        }

        $uploadDir = realpath($this->uploadService->getUploadDirectory());
        $fullPath = realpath($uploadDir . '/' . $imagePath);

        if ($fullPath === false || !str_starts_with($fullPath, $uploadDir . '/')) {
            $this->logger->critical('Tentative de suppression hors répertoire upload (path traversal)', [
                'bl_id' => $bonLivraison->getId(),
                'image_path' => $imagePath,
            ]);
            return;
        }

        if (!file_exists($fullPath)) {
            $this->logger->warning('Image introuvable sur le filesystem', [
                'bl_id' => $bonLivraison->getId(),
                'path' => $fullPath,
            ]);
            return;
        }

        unlink($fullPath);
    }
}
