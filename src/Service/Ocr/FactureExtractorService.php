<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\Entity\FactureFournisseur;
use App\Entity\LigneFactureFournisseur;
use App\Enum\StatutFacture;
use App\Repository\FournisseurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FactureExtractorService
{
    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly OcrMatchingService $ocrMatchingService,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Extract invoice data from the uploaded document via Claude Vision OCR.
     * Updates the FactureFournisseur entity and creates LigneFactureFournisseur entities.
     *
     * @return array{success: bool, warnings: string[]}
     */
    public function extract(FactureFournisseur $facture): array
    {
        $warnings = [];

        try {
            // 1. Get the document path
            $documentPath = $this->getDocumentPath($facture);
            if ($documentPath === null) {
                return ['success' => false, 'warnings' => ['Aucun document associé à la facture']];
            }

            // 2. Call Claude Vision API
            $prompt = $this->buildExtractionPrompt();

            $this->logger->info('[OCR Facture] Démarrage extraction', [
                'facture_id' => $facture->getIdAsString(),
                'document' => $facture->getDocumentOriginalPath(),
            ]);

            $response = $this->anthropicClient->analyzeImage($documentPath, $prompt);

            // 3. Log raw response BEFORE any processing (CLAUDE.md rule)
            $this->logger->info('[OCR Facture] Réponse brute Claude Vision', [
                'facture_id' => $facture->getIdAsString(),
                'raw_response' => $response['content'],
                'usage' => $response['usage'],
            ]);

            // 4. Parse JSON
            $data = $this->parseResponse($response['content']);
            if ($data === null) {
                return ['success' => false, 'warnings' => ['Impossible de parser la réponse JSON de l\'API']];
            }

            // 5. Store raw OCR data
            $facture->setOcrRawData($data);
            $facture->setOcrProcessedAt(new \DateTimeImmutable());

            // 6. Validate extraction
            $validationWarnings = $this->validateExtraction($data);
            $warnings = array_merge($warnings, $validationWarnings);

            // 7. Update facture header info
            $this->updateFactureInfo($facture, $data);

            // 8. Map lines
            $this->mapLignes($data, $facture);

            // 9. Update status: BROUILLON → RECUE
            $facture->setStatut(StatutFacture::RECUE);

            // 10. Add OCR remarks to warnings
            if (isset($data['remarques']) && is_array($data['remarques'])) {
                $warnings = array_merge($warnings, $data['remarques']);
            }

            $this->entityManager->flush();

            $this->logger->info('[OCR Facture] Extraction réussie', [
                'facture_id' => $facture->getIdAsString(),
                'numero' => $facture->getNumeroFacture(),
                'nb_lignes' => $facture->getLignes()->count(),
                'montant_ht' => $facture->getMontantHt(),
                'confiance' => $data['confiance'] ?? 'inconnue',
            ]);

            return ['success' => true, 'warnings' => $warnings];
        } catch (AnthropicApiException $e) {
            $this->logger->error('[OCR Facture] Erreur API Anthropic', [
                'facture_id' => $facture->getIdAsString(),
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'warnings' => ['Erreur API: ' . $e->getMessage()]];
        } catch (\Exception $e) {
            $this->logger->error('[OCR Facture] Erreur inattendue', [
                'facture_id' => $facture->getIdAsString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'warnings' => ['Erreur inattendue: ' . $e->getMessage()]];
        }
    }

    private function buildExtractionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en lecture de factures fournisseur pour la restauration professionnelle française.

ÉTAPE 1 — ANALYSE DU DOCUMENT

Avant d'extraire les données, identifie :
- Le type de document (facture, avoir, pro forma)
- Le fournisseur et son secteur (fruits/légumes, marée, viande, boissons, épicerie...)
- La structure du tableau de lignes (colonnes : code, désignation, quantité, unité, prix unitaire, TVA, montant...)
- Les totaux en pied de facture (HT, TVA par taux, TTC)
- Les éventuelles remises, majorations, consignes, déconsignes, droits d'accise

ÉTAPE 2 — EXTRACTION
Extrais TOUTES les informations dans le JSON ci-dessous.

RÈGLES CRITIQUES :
- Les nombres décimaux utilisent le POINT comme séparateur (pas la virgule)
- Si une valeur est illisible ou absente, mets null
- La "designation" doit être EXACTE, telle qu'imprimée — ne rien inventer
- Le "montant_ligne" est le montant HT de la ligne (quantite × prix_unitaire)
- Les taux de TVA sont en pourcentage (5.5, 10.0, 20.0...)
- Le numéro de facture et la date sont OBLIGATOIRES — cherche-les dans l'en-tête du document

Réponds UNIQUEMENT avec du JSON valide, sans texte avant ou après.

{
    "fournisseur": {
        "nom": "Nom commercial du fournisseur",
        "adresse": "Adresse complète ou null",
        "telephone": "Téléphone ou null",
        "siret": "SIRET (14 chiffres) ou null",
        "siren": "SIREN (9 premiers chiffres du SIRET) ou null",
        "tva_intracom": "Numéro TVA intracommunautaire (FR + 11 chiffres) ou null"
    },
    "acheteur": {
        "nom": "Nom du client/acheteur ou null",
        "tva_intracom": "TVA intracommunautaire du client ou null"
    },
    "document": {
        "type": "FACTURE",
        "numero": "Numéro de la facture tel qu'imprimé",
        "date_emission": "YYYY-MM-DD",
        "date_echeance": "YYYY-MM-DD ou null",
        "numero_commande": "Numéro de commande client ou null",
        "numero_bl": "Numéro du bon de livraison associé ou null",
        "devise": "EUR"
    },
    "lignes": [
        {
            "code_article": "Code article fournisseur ou null",
            "designation": "Désignation EXACTE telle qu'imprimée",
            "quantite": 4.35,
            "unite": "kg|p|L|bot|col|sac|flt|car|...",
            "prix_unitaire": 1.99,
            "taux_tva": 5.5,
            "montant_ligne": 8.66
        }
    ],
    "totaux": {
        "total_ht": 150.25,
        "remise_ht": null,
        "total_ht_net": 150.25,
        "tva": [
            { "taux": 5.5, "base": 100.00, "montant": 5.50 },
            { "taux": 20.0, "base": 50.25, "montant": 10.05 }
        ],
        "total_tva": 15.55,
        "total_ttc": 165.80,
        "consignes": null,
        "deconsignes": null,
        "droits_accise": null,
        "net_a_payer": 165.80
    },
    "confiance": "haute|moyenne|basse",
    "remarques": ["Difficultés rencontrées, valeurs incertaines, incohérences détectées"]
}

EXEMPLES DE PIÈGES COURANTS :
- Les virgules dans les nombres sur les factures françaises sont des séparateurs décimaux (1.990,00 = 1990.00 ; 4,35 = 4.35)
- Un espace dans un nombre est un séparateur de milliers (1 990,00 = 1990.00)
- Certaines factures ont des lignes de remise ou de majoration (ex: "Remise 5%") — les traiter comme des lignes normales avec un montant négatif
- Les consignes/déconsignes (boissons) sont des montants séparés, ne pas les confondre avec des lignes de produit
- Le SIRET fait 14 chiffres, le SIREN fait 9 chiffres (premiers chiffres du SIRET)
PROMPT;
    }

    private function parseResponse(string $jsonResponse): ?array
    {
        $jsonResponse = trim($jsonResponse);
        if (str_starts_with($jsonResponse, '```json')) {
            $jsonResponse = substr($jsonResponse, 7);
        }
        if (str_starts_with($jsonResponse, '```')) {
            $jsonResponse = substr($jsonResponse, 3);
        }
        if (str_ends_with($jsonResponse, '```')) {
            $jsonResponse = substr($jsonResponse, 0, -3);
        }
        $jsonResponse = trim($jsonResponse);

        $data = json_decode($jsonResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('[OCR Facture] Erreur parsing JSON', [
                'error' => json_last_error_msg(),
                'response_length' => strlen($jsonResponse),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * @return string[] Warnings
     */
    private function validateExtraction(array $data): array
    {
        $warnings = [];

        if (empty($data['fournisseur']['nom'])) {
            $warnings[] = 'Nom du fournisseur non détecté';
        }

        if (empty($data['document']['numero'])) {
            $warnings[] = 'Numéro de facture non détecté';
        }

        if (empty($data['document']['date_emission'])) {
            $warnings[] = 'Date d\'émission non détectée';
        }

        if (empty($data['lignes']) || !is_array($data['lignes'])) {
            $warnings[] = 'Aucune ligne de produit détectée';
            return $warnings;
        }

        // Validate line totals
        foreach ($data['lignes'] as $index => $ligne) {
            $qte = $ligne['quantite'] ?? null;
            $pu = $ligne['prix_unitaire'] ?? null;
            $total = $ligne['montant_ligne'] ?? null;

            if ($qte !== null && $pu !== null && $total !== null) {
                $calculated = $qte * $pu;
                $diff = abs($calculated - $total);

                if ($diff > 0.10) {
                    $designation = $ligne['designation'] ?? 'Ligne ' . ($index + 1);
                    $warnings[] = sprintf(
                        '%s : total calculé (%.2f) ≠ total facture (%.2f)',
                        mb_substr($designation, 0, 30),
                        $calculated,
                        $total
                    );
                }
            }
        }

        // Validate document total vs sum of lines
        $totalHt = $data['totaux']['total_ht'] ?? null;
        if ($totalHt !== null) {
            $sumLines = array_sum(array_map(
                fn ($l) => $l['montant_ligne'] ?? 0,
                $data['lignes']
            ));
            $diff = abs($sumLines - $totalHt);

            if ($diff > 0.50) {
                $warnings[] = sprintf(
                    'Total HT calculé (%.2f€) ≠ total facture (%.2f€)',
                    $sumLines,
                    $totalHt
                );
            }
        }

        return $warnings;
    }

    private function updateFactureInfo(FactureFournisseur $facture, array $data): void
    {
        $docData = $data['document'] ?? [];
        $fournisseurData = $data['fournisseur'] ?? [];
        $acheteurData = $data['acheteur'] ?? [];
        $totaux = $data['totaux'] ?? [];

        // Document info
        if (!empty($docData['numero'])) {
            $facture->setNumeroFacture($docData['numero']);
        }

        if (!empty($docData['date_emission'])) {
            try {
                $facture->setDateEmission(new \DateTimeImmutable($docData['date_emission']));
            } catch (\Exception) {
            }
        }

        if (!empty($docData['devise'])) {
            $facture->setDevise($docData['devise']);
        }

        // Fournisseur info
        if (empty($facture->getFournisseurNom()) && !empty($fournisseurData['nom'])) {
            $facture->setFournisseurNom($fournisseurData['nom']);
        }
        if (!empty($fournisseurData['tva_intracom'])) {
            $facture->setFournisseurTva($fournisseurData['tva_intracom']);
        }
        if (!empty($fournisseurData['siren'])) {
            $facture->setFournisseurSiren($fournisseurData['siren']);
        }

        // Try to match fournisseur by name if not already set
        if ($facture->getFournisseur() === null && !empty($fournisseurData['nom'])) {
            $this->tryMatchFournisseur($facture, $fournisseurData['nom']);
        }

        // Acheteur info
        if (!empty($acheteurData['nom'])) {
            $facture->setAcheteurNom($acheteurData['nom']);
        }
        if (!empty($acheteurData['tva_intracom'])) {
            $facture->setAcheteurTva($acheteurData['tva_intracom']);
        }

        // Totaux
        if (isset($totaux['total_ht'])) {
            $facture->setMontantHt((string) $totaux['total_ht']);
        }
        if (isset($totaux['total_tva'])) {
            $facture->setMontantTva((string) $totaux['total_tva']);
        }
        if (isset($totaux['total_ttc'])) {
            $facture->setMontantTtc((string) $totaux['total_ttc']);
        } elseif (isset($totaux['net_a_payer'])) {
            $facture->setMontantTtc((string) $totaux['net_a_payer']);
        }
    }

    private function tryMatchFournisseur(FactureFournisseur $facture, string $nom): void
    {
        $etablissement = $facture->getEtablissement();
        if ($etablissement === null) {
            return;
        }

        // Search for a matching fournisseur by name within the organisation
        $fournisseurs = $this->fournisseurRepository->findByOrganisation(
            $etablissement->getOrganisation()
        );

        foreach ($fournisseurs as $fournisseur) {
            $similarity = 0;
            similar_text(
                strtolower($nom),
                strtolower($fournisseur->getNom()),
                $similarity
            );

            if ($similarity >= 80) {
                $facture->setFournisseur($fournisseur);
                $this->logger->info('[OCR Facture] Fournisseur matché', [
                    'ocr_nom' => $nom,
                    'matched_nom' => $fournisseur->getNom(),
                    'similarity' => round($similarity),
                ]);
                return;
            }
        }
    }

    private function mapLignes(array $data, FactureFournisseur $facture): void
    {
        if (!isset($data['lignes']) || !is_array($data['lignes'])) {
            return;
        }

        $fournisseur = $facture->getFournisseur();

        foreach ($data['lignes'] as $ligneData) {
            $ligne = new LigneFactureFournisseur();
            $ligne->setFacture($facture);

            $ligne->setCodeArticle($ligneData['code_article'] ?? null);
            $ligne->setDesignation($ligneData['designation'] ?? 'Produit inconnu');
            $ligne->setQuantite(
                isset($ligneData['quantite']) ? (string) $ligneData['quantite'] : '0'
            );
            $ligne->setPrixUnitaire(
                isset($ligneData['prix_unitaire']) ? (string) $ligneData['prix_unitaire'] : '0'
            );
            $ligne->setMontantLigne(
                isset($ligneData['montant_ligne']) ? (string) $ligneData['montant_ligne'] : '0'
            );
            $ligne->setTauxTva(
                isset($ligneData['taux_tva']) ? (string) $ligneData['taux_tva'] : null
            );
            $ligne->setUnite($ligneData['unite'] ?? null);

            // Matching produit via service mutualisé
            if ($fournisseur !== null) {
                $matchResult = $this->ocrMatchingService->matchLigne(
                    !empty($ligneData['code_article']) ? (string) $ligneData['code_article'] : null,
                    $ligneData['designation'] ?? null,
                    $fournisseur,
                );
                $ligne->setProduit($matchResult->produitFournisseur);
            }

            $facture->addLigne($ligne);
            $this->entityManager->persist($ligne);
        }
    }

    private function getDocumentPath(FactureFournisseur $facture): ?string
    {
        $path = $facture->getDocumentOriginalPath();
        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return file_exists($path) ? $path : null;
        }

        $fullPath = $this->projectDir . '/var/factures/' . $path;

        return file_exists($fullPath) ? $fullPath : null;
    }
}
