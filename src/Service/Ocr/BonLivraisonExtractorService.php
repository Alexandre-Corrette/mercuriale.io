<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\DTO\ExtractionResult;
use App\Entity\BonLivraison;
use App\Entity\LigneBonLivraison;
use App\Entity\Unite;
use App\Enum\MatchConfidence;
use App\Enum\TypeUnite;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\UniteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BonLivraisonExtractorService
{
    private const UNITE_MAPPING = [
        // Poids
        'kg' => 'KG',
        'kilo' => 'KG',
        'kilogramme' => 'KG',
        'kilogrammes' => 'KG',
        'g' => 'KG',
        'gr' => 'KG',
        'gramme' => 'KG',
        'grammes' => 'KG',
        // Volume
        'l' => 'L',
        'litre' => 'L',
        'litres' => 'L',
        'cl' => 'L',
        'centilitre' => 'L',
        'centilitres' => 'L',
        'ml' => 'L',
        'millilitre' => 'L',
        'millilitres' => 'L',
        // Pièce / unité
        'p' => 'PU',
        'pc' => 'PU',
        'pce' => 'PU',
        'piece' => 'PU',
        'pièce' => 'PU',
        'pieces' => 'PU',
        'pièces' => 'PU',
        'u' => 'UNI',
        'pu' => 'PU',
        'uni' => 'UNI',
        'unite' => 'UNI',
        'unité' => 'UNI',
        'unites' => 'UNI',
        'unités' => 'UNI',
        // Conditionnements
        'bq' => 'BQT',
        'bqt' => 'BQT',
        'barquette' => 'BQT',
        'barquettes' => 'BQT',
        'bt' => 'BOT',
        'bot' => 'BOT',
        'bouteille' => 'BOT',
        'bouteilles' => 'BOT',
        'ct' => 'CAR',
        'carton' => 'CAR',
        'cartons' => 'CAR',
        'crt' => 'CAR',
        'col' => 'COL',
        'colis' => 'COL',
        'flt' => 'COL',
        'filet' => 'COL',
        'sac' => 'SAC',
        'fut' => 'FUT',
        'lot' => 'PU',
        'lots' => 'PU',
        'car' => 'CAR',
        'caisse' => 'CAR',
    ];

    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProduitFournisseurRepository $produitFournisseurRepository,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly UniteRepository $uniteRepository,
        private readonly OcrMatchingService $ocrMatchingService,
        private readonly ExtractionValidator $extractionValidator,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Extrait les données d'un bon de livraison à partir de son image.
     */
    public function extract(BonLivraison $bl): ExtractionResult
    {
        $startTime = microtime(true);
        $warnings = [];
        $produitsNonMatches = [];
        $this->uniteCache = [];

        try {
            // 1. Récupérer l'image
            $imagePath = $this->getImagePath($bl);
            if ($imagePath === null) {
                return new ExtractionResult(
                    success: false,
                    warnings: ['Aucune image associée au bon de livraison'],
                );
            }

            // 2. Appeler l'API Claude (la compression est gérée dans AnthropicClient)
            $prompt = $this->buildExtractionPrompt();
            $response = $this->anthropicClient->analyzeImage($imagePath, $prompt);

            // 3. Parser la réponse JSON
            $data = $this->parseResponse($response['content']);
            if ($data === null) {
                return new ExtractionResult(
                    success: false,
                    warnings: ['Impossible de parser la réponse de l\'API'],
                );
            }

            // 4. Valider et enrichir les données
            $validationWarnings = $this->validateExtraction($data);
            $warnings = array_merge($warnings, $validationWarnings);

            // 4b. Validation métier (date, rangs, totaux)
            $businessErrors = $this->extractionValidator->validate($data);
            if (!empty($businessErrors)) {
                $this->logger->warning('Erreurs validation extraction BL', [
                    'bl_id' => $bl->getId(),
                    'errors' => $businessErrors,
                ]);
                foreach ($businessErrors as $err) {
                    $warnings[] = 'Validation: ' . $err;
                }
            }

            // 5. Mettre à jour les infos du fournisseur si nouveau
            $this->updateFournisseurInfo($bl, $data);

            // 6. Mettre à jour les infos du BL
            $this->updateBonLivraisonInfo($bl, $data);

            // 7. Mapper les lignes
            $lignes = $this->mapLignes($data, $bl, $produitsNonMatches);

            // 8. Sauvegarder les données brutes
            $bl->setDonneesBrutes($data);

            // 9. Calculer le temps
            $tempsExtraction = round(microtime(true) - $startTime, 2);

            // 10. Ajouter les remarques de l'extraction aux warnings
            if (isset($data['remarques']) && is_array($data['remarques'])) {
                $warnings = array_merge($warnings, $data['remarques']);
            }

            $this->entityManager->flush();

            return new ExtractionResult(
                success: true,
                lignes: $lignes,
                warnings: $warnings,
                confiance: $data['confiance'] ?? 'basse',
                produitsNonMatches: $produitsNonMatches,
                tempsExtraction: $tempsExtraction,
                donneesBrutes: $data,
            );
        } catch (AnthropicApiException $e) {
            $this->logger->error('Erreur extraction BL', [
                'bl_id' => $bl->getId(),
                'error' => $e->getMessage(),
            ]);

            return new ExtractionResult(
                success: false,
                warnings: ['Erreur API: ' . $e->getMessage()],
                tempsExtraction: round(microtime(true) - $startTime, 2),
            );
        } catch (\Exception $e) {
            $this->logger->error('Erreur inattendue extraction BL', [
                'bl_id' => $bl->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new ExtractionResult(
                success: false,
                warnings: ['Erreur inattendue: ' . $e->getMessage()],
                tempsExtraction: round(microtime(true) - $startTime, 2),
            );
        }
    }

    private function buildExtractionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en lecture de bons de livraison (BL) de la société TerreAzur (groupe Pomona), fournisseur de fruits, légumes et produits de la mer pour la restauration professionnelle.

STRUCTURE SPÉCIFIQUE DES BL TERREAZUR :

Le tableau produits contient ces colonnes dans cet ordre :
[Rang/] [Code article] | Désignation | Qté livrée | Qté fact. UF / Poids brut | PU | MJ.DECOL | TVA | MT HT

Colonne de gauche (rang + code) :
- Format : "60/ 103634" → rang = 60, code_produit = "103634"
- La désignation commerciale est sur la ligne principale
- L'origine ou le sous-détail est sur la ligne en retrait en dessous (ex: "Jeunes pousses - France")

Colonnes quantité :
- "Qté livrée" = nombre de colis/bottes/bouquets physiquement livrés (COL, BOT, BQT...)
- "Qté fact. UF / Poids brut" = quantité facturée avec son unité (KG, PU, BOT...)
  → Quand il y a DEUX poids (ex: "6,100 KG / 6,800 KG"), le PREMIER est le poids livré, le SECOND est le poids facturé. Utilise le SECOND pour quantite_facturee.

Colonne MT HT :
- C'est la dernière colonne à droite
- Elle peut être partiellement coupée sur certains scans → lis-la attentivement
- C'est toujours : quantite_facturee × prix_unitaire ± majoration_decote

Totaux en bas du document :
- "Total X Colis" → nombre_colis (bas à gauche)
- "Poids XX,XXX kg" → poids_total_kg (bas à gauche, juste après)
- total_ht = SOMME de la colonne MT HT (ne pas confondre avec le numéro de commande)
- Le numéro de commande Pomona est sur la ligne "N° commande(s) Pomona XXXXXXXXXX" → ne pas mettre dans total_ht

RÈGLES CRITIQUES :
- Copie les désignations EXACTEMENT telles qu'imprimées. Si un mot est illisible → null pour ce champ, jamais une approximation ou invention
- Les nombres décimaux sur BL français utilisent la virgule (1,990 = 1.99 en JSON)
- "quantite_facturee × prix_unitaire" doit être PROCHE de "total_ht_ligne" — si l'écart est >5%, note-le dans remarques
- Si une valeur est illisible ou absente → null, jamais une valeur inventée
- Le champ "confiance" doit être "basse" si plus de 2 lignes ont des valeurs null ou incohérentes

Réponds UNIQUEMENT avec du JSON valide, sans texte avant ni après, sans bloc markdown.

{
    "fournisseur": {
        "nom": "Nom commercial tel qu'imprimé (ex: TerreAzur)",
        "groupe": "Groupe/maison mère si visible (ex: Pomona) ou null",
        "adresse": "Adresse complète ou null",
        "telephone": "Téléphone ou null",
        "siret": "SIRET si visible ou null"
    },
    "document": {
        "type": "BL",
        "numero": "Numéro après BORDEREAU DE LIVRAISON N°",
        "numero_commande": "Numéro après N° commande(s) Pomona",
        "date": "YYYY-MM-DD (date après 'du' dans le titre)",
        "client": "Nom du client livré (bloc LIVRÉ)",
        "page": "ex: 1/2 ou null si non visible"
    },
    "colonnes_detectees": ["En-têtes de colonnes tels que lus sur le document"],
    "lignes": [
        {
            "rang": 60,
            "code_produit": "103634",
            "designation": "Désignation EXACTE telle qu'imprimée ou null si illisible",
            "sous_detail": "Ligne en retrait sous la désignation (origine, variété) ou null",
            "origine": "Code pays si indiqué (FR, ES, MA...) ou null",
            "quantite_livree": 2.0,
            "unite_livraison": "COL|BOT|BQT|SAC|...",
            "quantite_facturee": 2.0,
            "unite_facturation": "KG|PU|BOT|BQT|...",
            "prix_unitaire": 7.110,
            "majoration_decote": 0.0,
            "total_ht_ligne": 14.22,
            "tva_code": "F 1|M 1|... tel qu'imprimé"
        }
    ],
    "totaux": {
        "nombre_colis": 9,
        "poids_total_kg": 35.610,
        "total_ht": null
    },
    "confiance": "haute|moyenne|basse",
    "remarques": ["Incohérences détectées, valeurs incertaines, colonnes coupées..."]
}
PROMPT;
    }

    /**
     * Parse la réponse JSON de Claude.
     */
    private function parseResponse(string $jsonResponse): ?array
    {
        $this->logger->info('Réponse brute OCR (full)', [
            'length' => strlen($jsonResponse),
            'content' => $jsonResponse,
        ]);

        // Nettoyer la réponse (enlever les backticks markdown si présents)
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

        // Try direct parse first
        $data = json_decode($jsonResponse, true);

        // If failed, try extracting JSON object from response text
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/u', $jsonResponse, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Erreur parsing JSON extraction', [
                'error' => json_last_error_msg(),
                'response_length' => strlen($jsonResponse),
                'first_200' => mb_substr($jsonResponse, 0, 200),
            ]);

            return null;
        }

        return $data;
    }

    /**
     * Valide la cohérence des données extraites.
     *
     * @return string[] Liste des warnings
     */
    private function validateExtraction(array $data): array
    {
        $warnings = [];

        if (empty($data['fournisseur']['nom'])) {
            $warnings[] = 'Nom du fournisseur non détecté';

        }

        if (empty($data['lignes']) || !is_array($data['lignes'])) {
            $warnings[] = 'Aucune ligne de produit détectée';

            return $warnings;
        }

        foreach ($data['lignes'] as $index => $ligne) {
            $qte = $ligne['quantite_facturee'] ?? null;
            $pu = $ligne['prix_unitaire'] ?? null;
            $total = $ligne['total_ht_ligne'] ?? $ligne['total_ligne'] ?? null;
            $mj = $ligne['majoration_decote'] ?? 0;

            if ($qte !== null && $pu !== null && $total !== null) {
                $calculatedTotal = ($qte * $pu) + $mj;
                $difference = abs($calculatedTotal - $total);

                // Tolérance de 0.10€ pour les arrondis et MJ non détectées
                if ($difference > 0.10) {
                    $designation = $ligne['designation'] ?? 'Ligne ' . ($index + 1);
                    $warnings[] = sprintf(
                        '%s : total calculé (%.2f) ≠ total BL (%.2f) — écart de %.2f€',
                        mb_substr($designation, 0, 30),
                        $calculatedTotal,
                        $total,
                        $difference
                    );
                }
            }

            if (empty($ligne['designation'])) {
                $warnings[] = sprintf('Ligne %d : désignation manquante', $index + 1);
            }
        }

        $totalHt = $data['totaux']['total_ht'] ?? $data['total_ht'] ?? null;
        if ($totalHt !== null && !empty($data['lignes'])) {
            $calculatedTotal = array_sum(array_map(
                fn ($l) => $l['total_ht_ligne'] ?? $l['total_ligne'] ?? 0,
                $data['lignes']
            ));
            $difference = abs($calculatedTotal - $totalHt);

            if ($difference > 0.50) {
                $warnings[] = sprintf(
                    'Total HT calculé (%.2f€) ≠ total BL (%.2f€)',
                    $calculatedTotal,
                    $totalHt
                );
            }
        }

        if (!empty($data['colonnes_detectees'])) {
            $this->logger->info('Colonnes détectées sur le BL', [
                'colonnes' => $data['colonnes_detectees'],
            ]);
        }

        return $warnings;
    }

    /**
     * Met à jour les informations du fournisseur si nécessaire.
     */
    private function updateFournisseurInfo(BonLivraison $bl, array $data): void
    {
        $fournisseur = $bl->getFournisseur();
        if ($fournisseur === null || !isset($data['fournisseur'])) {
            return;
        }

        $fournisseurData = $data['fournisseur'];

        // Mettre à jour les champs vides du fournisseur
        if (empty($fournisseur->getEmail()) && !empty($fournisseurData['email'])) {
            $fournisseur->setEmail($fournisseurData['email']);
        }
        if (empty($fournisseur->getTelephone()) && !empty($fournisseurData['telephone'])) {
            $fournisseur->setTelephone($fournisseurData['telephone']);
        }
        if (empty($fournisseur->getAdresse()) && !empty($fournisseurData['adresse'])) {
            $fournisseur->setAdresse($fournisseurData['adresse']);
        }
        if (empty($fournisseur->getSiret()) && !empty($fournisseurData['siret'])) {
            $fournisseur->setSiret($fournisseurData['siret']);
        }
    }

    /**
     * Met à jour les informations du bon de livraison.
     */
    private function updateBonLivraisonInfo(BonLivraison $bl, array $data): void
    {
        // Structure "document" (nouveau format) ou "bon_livraison" (ancien format)
        $docData = $data['document'] ?? $data['bon_livraison'] ?? null;
        if ($docData !== null) {
            if (empty($bl->getNumeroBl()) && !empty($docData['numero'])) {
                $bl->setNumeroBl($docData['numero']);
            }
            if (empty($bl->getNumeroBl()) && !empty($docData['numero_bl'])) {
                $bl->setNumeroBl($docData['numero_bl']);
            }
            if (empty($bl->getNumeroCommande()) && !empty($docData['numero_commande'])) {
                $bl->setNumeroCommande($docData['numero_commande']);
            }

            $dateField = $docData['date'] ?? $docData['date_livraison'] ?? null;
            if ($bl->getDateLivraison() === null && !empty($dateField)) {
                try {
                    $bl->setDateLivraison(new \DateTimeImmutable($dateField));
                } catch (\Exception) {
                }
            }
        }

        $totaux = $data['totaux'] ?? null;
        if ($totaux !== null && isset($totaux['total_ht'])) {
            $bl->setTotalHt((string) $totaux['total_ht']);
        } elseif (isset($data['total_ht'])) {
            $bl->setTotalHt((string) $data['total_ht']);
        }
    }

    /**
     * Mappe les lignes extraites vers des entités LigneBonLivraison.
     *
     * Logique de mapping des champs :
     * - quantiteLivree     ← quantite_livree du JSON (qté colis/pièces livrés)
     * - quantiteFacturee   ← quantite_facturee du JSON (qté réelle facturée = celle du calcul prix)
     * - quantiteCommandee  ← null (non présente sur les BL, uniquement sur les bons de commande)
     * - prixUnitaire       ← prix_unitaire du JSON (prix par unité de facturation)
     * - totalLigne         ← total_ht_ligne du JSON (montant HT final, avec MJ/DECOL inclus)
     * - unite              ← resolveUnite(unite_facturation) — c'est l'unité de FACTURATION qui compte pour la mercuriale
     *
     * @param array[] $produitsNonMatches
     *
     * @return LigneBonLivraison[]
     */
    private function mapLignes(array $data, BonLivraison $bl, array &$produitsNonMatches): array
    {
        $lignes = [];

        if (!isset($data['lignes']) || !is_array($data['lignes'])) {
            return $lignes;
        }

        $fournisseur = $bl->getFournisseur();
        $ordre = 0;

        foreach ($data['lignes'] as $ligneData) {
            $ordre++;

            $ligne = new LigneBonLivraison();
            $ligne->setBonLivraison($bl);
            $ligne->setOrdre($ordre);

            // Identifiants produit
            $ligne->setCodeProduitBl($ligneData['code_produit'] ?? null);
            $ligne->setDesignationBl($ligneData['designation'] ?? 'Produit inconnu');
            $ligne->setNumeroLigneBl(isset($ligneData['rang']) ? (int) $ligneData['rang'] : (isset($ligneData['numero_ligne']) ? (int) $ligneData['numero_ligne'] : null));
            $ligne->setOrigine($ligneData['origine'] ?? null);

            // Quantité livrée (nombre de colis/pièces)
            $ligne->setQuantiteLivree(
                isset($ligneData['quantite_livree']) ? (string) $ligneData['quantite_livree'] : null
            );
            $ligne->setUniteLivraison($ligneData['unite_livraison'] ?? null);

            // Quantité facturée (quantité réelle pour le calcul du prix)
            $ligne->setQuantiteFacturee(
                isset($ligneData['quantite_facturee']) ? (string) $ligneData['quantite_facturee'] : null
            );
            $ligne->setUniteFacturation($ligneData['unite_facturation'] ?? null);

            // Pas de quantité commandée sur un BL
            $ligne->setQuantiteCommandee(null);

            // Unité de référence = unité de facturation (celle de la mercuriale)
            $uniteFactStr = $ligneData['unite_facturation'] ?? 'PU';
            $unite = $this->resolveUnite($uniteFactStr);
            $ligne->setUnite($unite);

            // Prix
            $ligne->setPrixUnitaire(
                isset($ligneData['prix_unitaire']) ? (string) $ligneData['prix_unitaire'] : '0'
            );
            $ligne->setMajorationDecote(
                isset($ligneData['majoration_decote']) && $ligneData['majoration_decote'] != 0
                    ? (string) $ligneData['majoration_decote']
                    : null
            );
            $ligne->setTotalLigne(
                isset($ligneData['total_ht_ligne']) ? (string) $ligneData['total_ht_ligne'] : '0'
            );

            // TVA
            $ligne->setCodeTva($ligneData['tva_code'] ?? null);

            // Matching produit fournisseur via service mutualisé
            $produitFournisseur = null;
            if ($fournisseur !== null) {
                $matchResult = $this->ocrMatchingService->matchLigne(
                    !empty($ligneData['code_produit']) ? (string) $ligneData['code_produit'] : null,
                    $ligneData['designation'] ?? null,
                    $fournisseur,
                );

                $produitFournisseur = $matchResult->produitFournisseur;

                if (!$matchResult->isMatched()) {
                    $produitsNonMatches[] = [
                        'code' => $ligneData['code_produit'] ?? null,
                        'designation' => $ligneData['designation'] ?? null,
                        'confidence' => $matchResult->confidence->value,
                    ];
                }
            }
            $ligne->setProduitFournisseur($produitFournisseur);

            $bl->addLigne($ligne);
            $this->entityManager->persist($ligne);

            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /** @var array<string, Unite> Cache des unités résolues pendant l'extraction */
    private array $uniteCache = [];

    /**
     * Résout une unité depuis une chaîne extraite.
     */
    private function resolveUnite(string $uniteStr): Unite
    {
        $uniteStr = strtolower(trim($uniteStr));

        // Mapper vers le code standard (DB codes are uppercase)
        $code = self::UNITE_MAPPING[$uniteStr] ?? 'PU';

        // Retourner depuis le cache si déjà résolu
        if (isset($this->uniteCache[$code])) {
            return $this->uniteCache[$code];
        }

        // Chercher l'unité en base (try lowercase then uppercase)
        $unite = $this->uniteRepository->findOneBy(['code' => $code])
            ?? $this->uniteRepository->findOneBy(['code' => strtoupper($code)]);

        // Fallback sur PU (Pièce unitaire) si non trouvée
        if ($unite === null) {
            $unite = $this->uniteRepository->findOneBy(['code' => 'PU'])
                ?? $this->uniteRepository->findOneBy(['code' => 'p']);
        }

        // Créer l'unité pièce en dernier recours
        if ($unite === null) {
            $unite = new Unite();
            $unite->setCode('PU');
            $unite->setNom('Pièce unitaire');
            $unite->setType(TypeUnite::QUANTITE);
            $this->entityManager->persist($unite);
        }

        $this->uniteCache[$code] = $unite;

        return $unite;
    }

    /**
     * Retourne le chemin absolu de l'image du BL.
     */
    private function getImagePath(BonLivraison $bl): ?string
    {
        $imagePath = $bl->getImagePath();
        if ($imagePath === null) {
            return null;
        }

        // Si c'est déjà un chemin absolu
        if (str_starts_with($imagePath, '/')) {
            return file_exists($imagePath) ? $imagePath : null;
        }

        // Sinon, construire le chemin depuis le projet
        $fullPath = $this->projectDir . '/var/uploads/bon_livraison/' . $imagePath;

        return file_exists($fullPath) ? $fullPath : null;
    }
}
