<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\DTO\MatchResult;
use App\Entity\Fournisseur;
use App\Enum\MatchConfidence;
use App\Repository\ProduitFournisseurRepository;
use Psr\Log\LoggerInterface;

class OcrMatchingService
{
    private const FUZZY_THRESHOLD = 75.0;

    public function __construct(
        private readonly ProduitFournisseurRepository $produitFournisseurRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Match une ligne OCR avec la mercuriale du fournisseur.
     *
     * Priorité 1 : code article exact (fournisseur_id + code_fournisseur) — strict, pas de cross-fournisseur
     * Priorité 2 : fuzzy sur la désignation (seuil 75%)
     * Priorité 3 : aucun match
     */
    public function matchLigne(
        ?string $codeExtrait,
        ?string $designationExtraite,
        Fournisseur $fournisseur,
    ): MatchResult {
        // Priorité 1 — Match exact code article × fournisseur
        if ($codeExtrait !== null && $codeExtrait !== '') {
            $produit = $this->produitFournisseurRepository->findOneBy([
                'fournisseur' => $fournisseur,
                'codeFournisseur' => $codeExtrait,
                'actif' => true,
            ]);

            if ($produit !== null) {
                $this->logger->debug('[OCR Matching] Match exact code article', [
                    'code' => $codeExtrait,
                    'fournisseur' => $fournisseur->getNom(),
                    'produit' => $produit->getDesignationFournisseur(),
                ]);

                return new MatchResult($produit, MatchConfidence::EXACT, 'code_article', 100.0);
            }
        }

        // Priorité 2 — Fuzzy sur désignation
        if ($designationExtraite !== null && $designationExtraite !== '') {
            $produits = $this->produitFournisseurRepository->findByFournisseur($fournisseur);

            $bestMatch = null;
            $bestScore = 0.0;

            $designationNormalisee = mb_strtolower(trim($designationExtraite));

            foreach ($produits as $produit) {
                $designation = mb_strtolower(trim($produit->getDesignationFournisseur() ?? ''));
                $similarity = 0.0;
                similar_text($designationNormalisee, $designation, $similarity);

                if ($similarity > $bestScore) {
                    $bestScore = $similarity;
                    $bestMatch = $produit;
                }
            }

            if ($bestMatch !== null && $bestScore >= self::FUZZY_THRESHOLD) {
                $this->logger->debug('[OCR Matching] Match fuzzy désignation', [
                    'designation_ocr' => $designationExtraite,
                    'designation_mercuriale' => $bestMatch->getDesignationFournisseur(),
                    'score' => round($bestScore, 1),
                    'fournisseur' => $fournisseur->getNom(),
                ]);

                return new MatchResult($bestMatch, MatchConfidence::FUZZY, 'designation', round($bestScore, 1));
            }
        }

        // Priorité 3 — Aucun match
        $this->logger->debug('[OCR Matching] Aucun match', [
            'code' => $codeExtrait,
            'designation' => $designationExtraite,
            'fournisseur' => $fournisseur->getNom(),
        ]);

        return new MatchResult(null, MatchConfidence::NONE, 'none');
    }
}
