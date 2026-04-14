<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BonLivraison;
use App\Enum\StatutBonLivraison;
use App\Message\ProcessBonLivraisonOcrMessage;
use App\Service\BonLivraisonImageService;
use App\Service\Ocr\BonLivraisonExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessBonLivraisonOcrHandler
{
    public function __construct(
        private readonly BonLivraisonExtractorService $extractorService,
        private readonly BonLivraisonImageService $imageService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessBonLivraisonOcrMessage $message): void
    {
        $blId = $message->bonLivraisonId;

        $this->logger->info('[OCR BL Handler] Traitement BL', ['bl_id' => $blId]);

        $bl = $this->entityManager->getRepository(BonLivraison::class)->find($blId);

        if ($bl === null) {
            $this->logger->error('[OCR BL Handler] BL introuvable', ['bl_id' => $blId]);
            return;
        }

        if ($bl->getStatut() !== StatutBonLivraison::BROUILLON
            && $bl->getStatut() !== StatutBonLivraison::EN_COURS_OCR
        ) {
            $this->logger->warning('[OCR BL Handler] BL ignoré (statut incompatible)', [
                'bl_id' => $blId,
                'statut' => $bl->getStatut()->value,
            ]);
            return;
        }

        $bl->setStatut(StatutBonLivraison::EN_COURS_OCR);
        $this->entityManager->flush();

        try {
            $result = $this->extractorService->extract($bl);

            if (!$result->success) {
                $bl->setStatut(StatutBonLivraison::ECHEC_OCR);
                $bl->setNotes('Échec OCR. Consultez les logs pour plus de détails.');
                $this->entityManager->flush();

                $this->logger->error('[OCR BL Handler] Extraction échouée', [
                    'bl_id' => $blId,
                    'warnings' => $result->warnings,
                ]);
                return;
            }

            // Extraction réussie — supprimer l'image (le BL papier fait foi)
            try {
                $this->imageService->deleteImage($bl);
                $bl->setImagePath(null);
            } catch (\Throwable $e) {
                $this->logger->error('[OCR BL Handler] Impossible de supprimer l\'image', [
                    'bl_id' => $blId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Positionner le statut selon le résultat de l'extraction
            if ($result->produitsNonMatches !== [] || !empty($result->warnings)) {
                $bl->setStatut(StatutBonLivraison::ANOMALIE);
            } else {
                $bl->setStatut(StatutBonLivraison::VALIDE);
            }
            $this->entityManager->flush();

            $this->logger->info('[OCR BL Handler] Extraction terminée', [
                'bl_id' => $blId,
                'statut' => $bl->getStatut()->value,
                'nb_lignes' => count($result->lignes),
            ]);
        } catch (\Throwable $e) {
            $bl->setStatut(StatutBonLivraison::ECHEC_OCR);
            $bl->setNotes('Erreur OCR inattendue. Consultez les logs pour plus de détails.');
            $this->entityManager->flush();

            $this->logger->error('[OCR BL Handler] Exception pendant extraction', [
                'bl_id' => $blId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Ne pas re-throw : le BL est marqué ECHEC_OCR, le message est acquitté.
            // Re-throw causait une boucle de retry (client 3× + Messenger 5× = 15 appels API).
        }
    }
}
