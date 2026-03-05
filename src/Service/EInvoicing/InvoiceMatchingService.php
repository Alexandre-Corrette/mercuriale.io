<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\LigneFactureFournisseur;
use App\Enum\StatutFacture;
use App\Repository\BonLivraisonRepository;
use App\Repository\FactureFournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class InvoiceMatchingService
{
    /** Date tolerance: search BLs within ±7 days of invoice date */
    private const DATE_TOLERANCE_DAYS = 7;

    /** Total HT tolerance: max 2% écart for exact match */
    private const TOTAL_HT_TOLERANCE_PERCENT = 2.0;

    /** Score thresholds */
    private const SCORE_RAPPROCHE = 70;

    public function __construct(
        private readonly BonLivraisonRepository $blRepo,
        private readonly FactureFournisseurRepository $factureRepo,
        private readonly EntityManagerInterface $entityManager,
        private readonly PdpClientInterface $pdpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Attempt to match a single facture to a BL.
     * Returns the matched BL or null if no match found.
     */
    public function matchFacture(FactureFournisseur $facture): ?BonLivraison
    {
        $fournisseur = $facture->getFournisseur();
        $etablissement = $facture->getEtablissement();
        $dateEmission = $facture->getDateEmission();

        if ($fournisseur === null || $etablissement === null || $dateEmission === null) {
            return null;
        }

        // Search BLs within date range
        $from = $dateEmission->modify(sprintf('-%d days', self::DATE_TOLERANCE_DAYS));
        $to = $dateEmission->modify(sprintf('+%d days', self::DATE_TOLERANCE_DAYS));

        $candidateBLs = $this->blRepo->findByFournisseurAndPeriod(
            $fournisseur,
            $etablissement,
            $from,
            $to,
        );

        if (\count($candidateBLs) === 0) {
            $this->logger->debug('[Rapprochement] Aucun BL candidat', [
                'facture_id' => $facture->getIdAsString(),
                'fournisseur_id' => $fournisseur->getId(),
                'date_emission' => $dateEmission->format('Y-m-d'),
            ]);

            return null;
        }

        // Score each candidate BL and pick the best
        $bestBL = null;
        $bestScore = 0;
        $bestEcart = null;

        foreach ($candidateBLs as $bl) {
            $result = $this->scoreBL($facture, $bl);

            if ($result['score'] > $bestScore) {
                $bestScore = $result['score'];
                $bestBL = $bl;
                $bestEcart = $result['ecartHt'];
            }
        }

        if ($bestBL === null || $bestScore < self::SCORE_RAPPROCHE) {
            $this->logger->info('[Rapprochement] Aucun BL avec score suffisant', [
                'facture_id' => $facture->getIdAsString(),
                'best_score' => $bestScore,
                'threshold' => self::SCORE_RAPPROCHE,
                'candidates' => \count($candidateBLs),
            ]);

            return null;
        }

        // Apply the rapprochement
        $this->entityManager->wrapInTransaction(function () use ($facture, $bestBL, $bestScore, $bestEcart): void {
            $facture->setBonLivraison($bestBL);
            $facture->setStatut(StatutFacture::RAPPROCHEE);
            $facture->setRapprocheLe(new \DateTimeImmutable());
            $facture->setScoreRapprochement($bestScore);
            $facture->setEcartMontantHt($bestEcart);
        });

        // Sync with B2Brouter (non-blocking)
        $this->syncStatus($facture);

        $this->logger->info('[Rapprochement] Facture rapprochée', [
            'facture_id' => $facture->getIdAsString(),
            'bl_id' => $bestBL->getId(),
            'score' => $bestScore,
            'ecart_ht' => $bestEcart,
        ]);

        return $bestBL;
    }

    /**
     * Match all unmatched factures for a given établissement.
     *
     * @return array{matched: int, unmatched: int}
     */
    public function matchAllForEtablissement(Etablissement $etablissement): array
    {
        $factures = $this->factureRepo->findUnmatchedForEtablissement($etablissement);

        $matched = 0;
        $unmatched = 0;

        foreach ($factures as $facture) {
            $bl = $this->matchFacture($facture);
            if ($bl !== null) {
                ++$matched;
            } else {
                ++$unmatched;
            }
        }

        $this->logger->info('[Rapprochement] Batch terminé', [
            'etablissement_id' => $etablissement->getId(),
            'matched' => $matched,
            'unmatched' => $unmatched,
        ]);

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Score a BL against a facture.
     * Returns ['score' => 0-100, 'ecartHt' => string decimal].
     *
     * @return array{score: int, ecartHt: string}
     */
    private function scoreBL(FactureFournisseur $facture, BonLivraison $bl): array
    {
        $score = 0;

        // 1. Total HT comparison (40 points max)
        $factureMontantHt = $facture->getMontantHt();
        $blTotalHt = $bl->getTotalHt();
        $ecartHt = '0.00';

        if ($factureMontantHt !== null && $blTotalHt !== null) {
            $ecartHt = bcsub($factureMontantHt, $blTotalHt, 2);
            $ecartPercent = $this->calculateEcartPercent($factureMontantHt, $blTotalHt);

            if ($ecartPercent <= self::TOTAL_HT_TOLERANCE_PERCENT) {
                $score += 40; // Exact match
            } elseif ($ecartPercent <= 5.0) {
                $score += 25; // Close match
            } elseif ($ecartPercent <= 10.0) {
                $score += 10; // Approximate
            }
        }

        // 2. Line matching (40 points max)
        $lineScore = $this->scoreLines($facture, $bl);
        $score += (int) round($lineScore * 40);

        // 3. Date proximity (20 points max)
        $dateScore = $this->scoreDateProximity($facture, $bl);
        $score += (int) round($dateScore * 20);

        return ['score' => min($score, 100), 'ecartHt' => $ecartHt];
    }

    /**
     * Compare invoice lines to BL lines. Returns 0.0 to 1.0.
     */
    private function scoreLines(FactureFournisseur $facture, BonLivraison $bl): float
    {
        $factureLines = $facture->getLignes()->toArray();
        $blLines = $bl->getLignes()->toArray();

        if (\count($factureLines) === 0 || \count($blLines) === 0) {
            return 0.0;
        }

        $matchedCount = 0;
        $totalLines = \count($factureLines);
        $usedBLLines = [];

        foreach ($factureLines as $fLine) {
            $bestMatchIdx = $this->findBestLineMatch($fLine, $blLines, $usedBLLines);
            if ($bestMatchIdx !== null) {
                $usedBLLines[$bestMatchIdx] = true;
                ++$matchedCount;
            }
        }

        return $totalLines > 0 ? $matchedCount / $totalLines : 0.0;
    }

    /**
     * Find the best matching BL line for an invoice line.
     * Matching criteria: code article (exact), then designation (fuzzy), then qty + price.
     *
     * @param LigneBonLivraison[] $blLines
     * @param array<int, bool> $usedBLLines
     */
    private function findBestLineMatch(
        LigneFactureFournisseur $factureLine,
        array $blLines,
        array $usedBLLines,
    ): ?int {
        $bestIdx = null;
        $bestScore = 0;

        foreach ($blLines as $idx => $blLine) {
            if (isset($usedBLLines[$idx])) {
                continue;
            }

            $lineScore = 0;

            // Code article match (strongest signal)
            $fCode = $factureLine->getCodeArticle();
            $blCode = $blLine->getCodeProduitBl();
            if ($fCode !== null && $blCode !== null && $fCode === $blCode) {
                $lineScore += 50;
            }

            // Designation match (fuzzy)
            $fDesig = mb_strtolower(trim($factureLine->getDesignation() ?? ''));
            $blDesig = mb_strtolower(trim($blLine->getDesignationBl() ?? ''));
            if ($fDesig !== '' && $blDesig !== '') {
                if ($fDesig === $blDesig) {
                    $lineScore += 30;
                } elseif (str_contains($fDesig, $blDesig) || str_contains($blDesig, $fDesig)) {
                    $lineScore += 20;
                } else {
                    similar_text($fDesig, $blDesig, $percent);
                    if ($percent > 70) {
                        $lineScore += (int) ($percent / 10);
                    }
                }
            }

            // Quantity match
            $fQty = (float) ($factureLine->getQuantite() ?? '0');
            $blQty = (float) ($blLine->getQuantiteLivree() ?? '0');
            if ($fQty > 0 && $blQty > 0 && abs($fQty - $blQty) / max($fQty, $blQty) < 0.05) {
                $lineScore += 10;
            }

            // Unit price match
            $fPU = (float) ($factureLine->getPrixUnitaire() ?? '0');
            $blPU = (float) ($blLine->getPrixUnitaire() ?? '0');
            if ($fPU > 0 && $blPU > 0 && abs($fPU - $blPU) / max($fPU, $blPU) < 0.05) {
                $lineScore += 10;
            }

            // Must have minimum relevance
            if ($lineScore >= 30 && $lineScore > $bestScore) {
                $bestScore = $lineScore;
                $bestIdx = $idx;
            }
        }

        return $bestIdx;
    }

    /**
     * Score date proximity between facture and BL. Returns 0.0 to 1.0.
     * Same day = 1.0, ±1 day = 0.9, etc.
     */
    private function scoreDateProximity(FactureFournisseur $facture, BonLivraison $bl): float
    {
        $factureDate = $facture->getDateEmission();
        $blDate = $bl->getDateLivraison();

        if ($factureDate === null || $blDate === null) {
            return 0.0;
        }

        $daysDiff = abs((int) $factureDate->diff($blDate)->days);

        if ($daysDiff === 0) {
            return 1.0;
        }

        if ($daysDiff > self::DATE_TOLERANCE_DAYS) {
            return 0.0;
        }

        return 1.0 - ($daysDiff / (self::DATE_TOLERANCE_DAYS + 1));
    }

    /**
     * Calculate écart % between two amounts.
     */
    private function calculateEcartPercent(string $amount1, string $amount2): float
    {
        $a1 = (float) $amount1;
        $a2 = (float) $amount2;

        if ($a2 == 0) {
            return $a1 == 0 ? 0.0 : 100.0;
        }

        return abs(($a1 - $a2) / $a2) * 100;
    }

    private function syncStatus(FactureFournisseur $facture): void
    {
        $externalId = $facture->getExternalId();
        if ($externalId === null) {
            return;
        }

        try {
            $this->pdpClient->updateInvoiceStatus($externalId, 'matched');
        } catch (PdpApiException $e) {
            $this->logger->warning('[Rapprochement] Échec synchronisation statut B2Brouter', [
                'facture_id' => $facture->getIdAsString(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
