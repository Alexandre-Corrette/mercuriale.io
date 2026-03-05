<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\FactureFournisseur;
use App\Enum\StatutFacture;
use App\Message\ProcessFactureOcrMessage;
use App\Service\Ocr\FactureExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
class ProcessFactureOcrHandler
{
    public function __construct(
        private readonly FactureExtractorService $extractorService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessFactureOcrMessage $message): void
    {
        $factureId = $message->factureId;

        $this->logger->info('[OCR Handler] Traitement facture', ['facture_id' => $factureId]);

        // Load the facture
        $facture = $this->entityManager->getRepository(FactureFournisseur::class)->find(
            Uuid::fromString($factureId)
        );

        if ($facture === null) {
            $this->logger->error('[OCR Handler] Facture introuvable', ['facture_id' => $factureId]);
            return;
        }

        // Only process BROUILLON factures (not already processed)
        if ($facture->getStatut() !== StatutFacture::BROUILLON) {
            $this->logger->warning('[OCR Handler] Facture ignorée (statut non BROUILLON)', [
                'facture_id' => $factureId,
                'statut' => $facture->getStatut()->value,
            ]);
            return;
        }

        // Extract data via Claude Vision
        $result = $this->extractorService->extract($facture);

        if (!$result['success']) {
            $this->logger->error('[OCR Handler] Extraction échouée', [
                'facture_id' => $factureId,
                'warnings' => $result['warnings'],
            ]);

            // Store the failure info but don't change status (stays BROUILLON for retry)
            $facture->setOcrProcessedAt(new \DateTimeImmutable());
            $facture->setCommentaire('Échec OCR : ' . implode(' | ', $result['warnings']));
            $this->entityManager->flush();
            return;
        }

        $this->logger->info('[OCR Handler] Extraction terminée avec succès', [
            'facture_id' => $factureId,
            'numero' => $facture->getNumeroFacture(),
            'nb_lignes' => $facture->getLignes()->count(),
            'warnings_count' => count($result['warnings']),
        ]);
    }
}
