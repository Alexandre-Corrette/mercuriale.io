<?php

declare(strict_types=1);

namespace App\Service\BonLivraison;

use App\Entity\BonLivraison;
use App\Service\Upload\BonLivraisonUploadService;
use Doctrine\ORM\EntityManagerInterface;

class RejectBonLivraisonService
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Delete a BL and its associated image file.
     */
    public function reject(BonLivraison $bonLivraison): void
    {
        $imagePath = $bonLivraison->getImagePath();
        if ($imagePath) {
            $fullPath = $this->uploadService->getUploadDirectory() . '/' . $imagePath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        $this->entityManager->remove($bonLivraison);
        $this->entityManager->flush();
    }
}
