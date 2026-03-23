<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\DTO\ExtractionResult;
use App\Entity\BonLivraison;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\Unite;
use App\Enum\MatchConfidence;
use App\Enum\StatutBonLivraison;
use App\Enum\TypeUnite;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use App\Repository\UniteRepository;
use App\Service\Unit\UnitNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BonLivraisonExtractorService
{
    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly BonLivraisonRepository $bonLivraisonRepository,
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
        // $uniteCache is now a local variable passed by reference into mapLignes/resolveUnite (see below)

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
            $prompt = $this->buildExtractionPrompt($bl);
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

                // Retry once on critical errors (missing lines, incoherent date) with a more explicit prompt
                if ($this->extractionValidator->isCritical($businessErrors)) {
                    $this->logger->info('Erreur critique détectée, nouvelle tentative d\'extraction', [
                        'bl_id' => $bl->getId(),
                        'errors' => $businessErrors,
                    ]);
                    $retryPrompt = $this->buildExtractionPrompt($bl) . "\n\nATTENTION : Une extraction précédente a retourné des erreurs critiques : " . implode(', ', $businessErrors) . ". Sois particulièrement attentif à lire TOUTES les lignes du tableau et à bien identifier la date du document.";
                    $retryResponse = $this->anthropicClient->analyzeImage($imagePath, $retryPrompt);
                    $retryData = $this->parseResponse($retryResponse['content']);
                    if ($retryData !== null) {
                        $retryErrors = $this->extractionValidator->validate($retryData);
                        // Use retry result only if it's strictly better (fewer critical errors)
                        if (count($retryErrors) < count($businessErrors)) {
                            $this->logger->info('Retry extraction améliorée, utilisation du résultat de la seconde tentative', [
                                'bl_id' => $bl->getId(),
                                'initial_errors' => count($businessErrors),
                                'retry_errors' => count($retryErrors),
                            ]);
                            $data = $retryData;
                            $businessErrors = $retryErrors;
                        }
                    }
                }

                foreach ($businessErrors as $err) {
                    $warnings[] = 'Validation: ' . $err;
                }
            }

            // 5. Mettre à jour les infos du fournisseur si nouveau
            $this->updateFournisseurInfo($bl, $data);

            // 6. Mettre à jour les infos du BL
            $this->updateBonLivraisonInfo($bl, $data);

            // 6b. Vérification anti-doublon (fournisseur + numéro BL)
            if ($bl->getNumeroBl() !== null && $bl->getFournisseur() !== null && $bl->getEtablissement() !== null) {
                $doublon = $this->bonLivraisonRepository->findDuplicate(
                    $bl->getEtablissement(),
                    $bl->getFournisseur(),
                    $bl->getNumeroBl(),
                    $bl->getId(),
                );

                if ($doublon !== null) {
                    $bl->setStatut(StatutBonLivraison::DOUBLON);
                    $bl->setNotes('Doublon détecté : BL #' . $doublon->getId() . ' (même fournisseur et numéro ' . $bl->getNumeroBl() . ')');
                    $this->logger->warning('BL doublon détecté', [
                        'bl_id' => $bl->getId(),
                        'doublon_id' => $doublon->getId(),
                        'numero_bl' => $bl->getNumeroBl(),
                        'fournisseur' => $bl->getFournisseur()->getNom(),
                    ]);
                    $this->entityManager->flush();

                    return new ExtractionResult(
                        success: true,
                        lignes: [],
                        warnings: ['Ce bon de livraison est un doublon du BL #' . $doublon->getId() . ' (' . $bl->getNumeroBl() . ')'],
                        confiance: 'haute',
                        produitsNonMatches: [],
                        tempsExtraction: round(microtime(true) - $startTime, 2),
                        donneesBrutes: $data,
                    );
                }
            }

            // 7. Mapper les lignes
            $uniteCache = [];
            $lignes = $this->mapLignes($data, $bl, $produitsNonMatches, $uniteCache);

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

    private function buildExtractionPrompt(BonLivraison $bl): string
    {
        $fournisseur = $bl->getFournisseur();
        $nomFournisseur = $fournisseur?->getNom();

        // Sélectionner le contexte fournisseur spécifique si connu
        $fournisseurContext = $this->getFournisseurContext($nomFournisseur);

        return <<<PROMPT
Tu es un expert en lecture de bons de livraison (BL) pour la restauration professionnelle française.

OBJECTIF : Extraire TOUTES les données structurées d'un bon de livraison scanné/photographié.

APPROCHE GÉNÉRALE :
1. Identifie d'abord le fournisseur (nom, logo, en-tête)
2. Repère la structure du tableau produits (en-têtes de colonnes)
3. Extrais chaque ligne produit en respectant la structure détectée
4. Lis les totaux en bas du document

{$fournisseurContext}

RÈGLES CRITIQUES :
- Copie les désignations EXACTEMENT telles qu'imprimées. Si un mot est illisible → null pour ce champ, jamais une approximation ou invention
- Les nombres décimaux sur BL français utilisent la virgule (1,990 = 1.99 en JSON)
- "quantite_facturee × prix_unitaire" doit être PROCHE de "total_ht_ligne" — si l'écart est >5%, note-le dans remarques
- Si une valeur est illisible ou absente → null, jamais une valeur inventée
- Le champ "confiance" doit être "basse" si plus de 2 lignes ont des valeurs null ou incohérentes
- Distingue bien quantite_livree (colis/pièces physiques) et quantite_facturee (unité de facturation : KG, PU, etc.)
- Ne confonds pas un numéro de commande avec un total HT

Réponds UNIQUEMENT avec du JSON valide, sans texte avant ni après, sans bloc markdown.

{
    "fournisseur": {
        "nom": "Nom commercial tel qu'imprimé sur le document",
        "groupe": "Groupe/maison mère si visible ou null",
        "adresse": "Adresse complète ou null",
        "telephone": "Téléphone ou null",
        "siret": "SIRET si visible ou null"
    },
    "document": {
        "type": "BL",
        "numero": "Numéro du bordereau de livraison",
        "numero_commande": "Numéro de commande associé ou null",
        "date": "YYYY-MM-DD",
        "client": "Nom du client livré ou null",
        "page": "ex: 1/2 ou null si non visible"
    },
    "colonnes_detectees": ["En-têtes de colonnes tels que lus sur le document"],
    "lignes": [
        {
            "rang": null,
            "code_produit": "Code article fournisseur",
            "designation": "Désignation EXACTE telle qu'imprimée ou null si illisible",
            "sous_detail": "Ligne en retrait sous la désignation (origine, variété) ou null",
            "origine": "Code pays si indiqué (FR, ES, MA...) ou null",
            "quantite_livree": 2.0,
            "unite_livraison": "COL|BOT|BQT|SAC|KG|PU|...",
            "quantite_facturee": 2.0,
            "unite_facturation": "KG|PU|BOT|BQT|L|...",
            "prix_unitaire": 7.110,
            "majoration_decote": 0.0,
            "total_ht_ligne": 14.22,
            "tva_code": "Code TVA tel qu'imprimé ou null"
        }
    ],
    "totaux": {
        "nombre_colis": null,
        "poids_total_kg": null,
        "total_ht": null
    },
    "confiance": "haute|moyenne|basse",
    "remarques": ["Incohérences détectées, valeurs incertaines, colonnes coupées..."]
}
PROMPT;
    }

    private const SUPPLIER_KEYWORDS = [
        'terreazur' => 'getTerreAzurContext',
        'terre azur' => 'getTerreAzurContext',
        'pomona' => 'getTerreAzurContext',   // Pomona is the parent group of TerreAzur — same BL format
        'bihan' => 'getLeBihanContext',
        'tmeg' => 'getLeBihanContext',
        'brake' => 'getBrakeContext',
        'metro' => 'getMetroContext',
        'transgourmet' => 'getTransgourmetContext',
        'promocash' => 'getTransgourmetContext',
        'sysco' => 'getSyscoContext',
        'davigel' => 'getSyscoContext',
    ];

    /**
     * Retourne le contexte spécifique au fournisseur pour enrichir le prompt OCR.
     */
    private function getFournisseurContext(?string $nomFournisseur): string
    {
        if ($nomFournisseur === null) {
            return $this->getGenericContext();
        }

        $normalized = self::normalizeName($nomFournisseur);

        foreach (self::SUPPLIER_KEYWORDS as $keyword => $method) {
            if (str_contains($normalized, $keyword)) {
                return $this->$method();
            }
        }

        return $this->getGenericContext();
    }

    /**
     * Normalise un nom de fournisseur : supprime accents, tirets, ponctuation, lowercase.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        // Suppression des accents
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterator !== null) {
                $name = $transliterator->transliterate($name);
            }
        } else {
            // Fallback sans ext-intl
            $name = strtr($name, [
                'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
                'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
                'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
                'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
                'ç' => 'c', 'ñ' => 'n',
            ]);
        }

        // Suppression tirets, underscores, points
        $name = str_replace(['-', '_', '.'], ' ', $name);

        // Suppression espaces multiples
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    private function getGenericContext(): string
    {
        return <<<'CTX'
GUIDE D'EXTRACTION UNIVERSEL — BL RESTAURATION FRANÇAISE :

ÉTAPE 1 — IDENTIFIER LA STRUCTURE :
- Lis d'abord les en-têtes de colonnes du tableau produits AVANT de lire les lignes
- Repère s'il y a une colonne de numéro de ligne (rang, n° ligne, #)
- Repère si les désignations ont des sous-détails en retrait (origine, variété, calibre, DLC)
- Les colonnes courantes sont : Code, Désignation, Quantité, Unité, Prix Unitaire, Total HT
- Colonnes supplémentaires possibles : Remise, Consigne, Déconsigne, TVA, Poids brut/net, Droits d'accise, DLC, Lot

ÉTAPE 2 — QUANTITÉS (CRITIQUE) :
- Beaucoup de BL ont DEUX colonnes quantité : quantité livrée (colis, pièces) et quantité facturée (KG, L, PU)
- Si UNE SEULE colonne quantité → l'utiliser comme quantite_livree ET quantite_facturee
- Si DEUX colonnes (ex: "Qté Cde / Qté Fac", "Qté livrée / Poids net", "Nb colis / Qté fact.") → mapper la première vers quantite_livree et la seconde vers quantite_facturee
- L'unité de facturation (celle du prix unitaire) va dans unite_facturation
- L'unité de livraison (colis, pièces physiques) va dans unite_livraison
- Si un champ contient DEUX valeurs (ex: "6,100 KG / 6,800 KG"), le PREMIER est le livré, le SECOND est le facturé

ÉTAPE 3 — UNITÉS FRANÇAISES :
- Abréviations courantes : KG, L, PU (pièce), COL (colis), BOT (bouteille), BQT (barquette), SAC, CAR (carton), FUT (fût), UNI (unité), FLT (filet), PLT (plateau), PCK (pack), BTE (boîte), PAL (palette)
- L'unité de facturation ≠ unité de livraison (ex: livré en COL, facturé en KG)
- Le prix unitaire correspond TOUJOURS à l'unité de facturation

ÉTAPE 4 — TOTAUX ET RÉCAPITULATIFS :
- Distinguer total HT (hors taxes), TVA et TTC (toutes taxes comprises)
- total_ht = somme des montants HT des lignes, HORS consignes, déconsignes et droits d'accise
- Ne JAMAIS confondre un numéro de commande, un numéro client ou un code tournée avec un montant
- Si plusieurs taux TVA (5,5%, 10%, 20%), le total_ht est la somme de toutes les bases HT
- Le numéro de BL est généralement en haut du document (en-tête, près du titre "Bon de livraison" ou "BL")

ÉTAPE 5 — MULTI-PAGES ET QUALITÉ :
- Si le document indique "page X/Y" ou "1/2, 2/2", le noter dans document.page
- Les totaux sont généralement sur la dernière page uniquement
- Les colonnes de droite (montants) sont souvent partiellement coupées sur les scans → null si illisible
- Zones d'ombre, pliures, taches → null plutôt que deviner une valeur
CTX;
    }

    private function getTerreAzurContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — TERREAZUR (groupe Pomona) :

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
- C'est la dernière colonne à droite, peut être partiellement coupée sur certains scans
- C'est toujours : quantite_facturee × prix_unitaire ± majoration_decote

Totaux en bas du document :
- "Total X Colis" → nombre_colis
- "Poids XX,XXX kg" → poids_total_kg
- total_ht = SOMME de la colonne MT HT (ne PAS confondre avec le numéro de commande Pomona)
- Le numéro de commande Pomona est sur la ligne "N° commande(s) Pomona XXXXXXXXXX"
CTX;
    }

    private function getLeBihanContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — LE BIHAN TMEG :

Numéro BL : format "002XXXXX"

Le tableau produits contient ces colonnes :
Code | Désignation | Ref Client | Quantité Unité Cde | Quantité Unité Fac | Prix Unitaire | Montant | Dt Remise | Dt Droits | Consigne | Déconsigne

Spécificités :
- "Quantité Unité Cde" = quantite_livree (commandée/livrée)
- "Quantité Unité Fac" = quantite_facturee (facturée)
- "Dt Droits" = droits d'accise (pour les alcools) → ignorer pour le total_ht_ligne
- "Consigne" / "Déconsigne" = montants de consigne bouteilles → ignorer pour le total_ht_ligne
- Le document peut être multi-pages (page 1/2, 2/2)
- Colonnes supplémentaires possibles : N° ACCISE, Vol Effectif, Alcool Pur, Poids Brut, Poids Net

Totaux en bas :
- Total TTC, Consignes, Total Facture
- Montant HT par taux TVA (5,50% et 20,00%)
- total_ht = somme des montants HT hors consignes et droits d'accise
CTX;
    }

    private function getBrakeContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — BRAKE (groupe Sysco) :

Fournisseur surgelés, épicerie et produits frais pour la restauration.

Numéro BL : format 8-10 chiffres, parfois préfixé "BL" ou "LIV".

Le tableau produits contient ces colonnes :
Code Article | Désignation | Qté Cde | Qté Liv | Unité | PU HT | Montant HT | TVA

Spécificités :
- "Qté Cde" = quantite commandée (utiliser comme quantite_livree si "Qté Liv" absente)
- "Qté Liv" = quantite_livree (quantité réellement livrée)
- L'unité est souvent l'unité de facturation : PCK (pack), CAR (carton), KG, PU, BTE (boîte)
- Si une seule colonne quantité → c'est à la fois quantite_livree et quantite_facturee
- Informations supplémentaires possibles : DLC, N° Lot, Température livraison
- Les remises/promotions sont parfois sur une ligne séparée sous le produit

Totaux en bas :
- Total HT, Total TVA (par taux), Total TTC
- total_ht = somme des montants HT lignes
CTX;
    }

    private function getMetroContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — METRO (Cash & Carry) :

Format ticket/facture plutôt que BL classique. Souvent un reçu de caisse professionnel.

Numéro de document : peut être "Facture", "Ticket" ou "BL" suivi de chiffres.

Le tableau produits contient ces colonnes :
Article/EAN | Désignation | Qté | PU HT | PU TTC | Montant | TVA%

Spécificités :
- UNE SEULE colonne quantité → utiliser comme quantite_livree ET quantite_facturee
- Le code article peut être un EAN (code-barres 13 chiffres) ou un code interne Metro
- Numéro de carte Metro souvent en en-tête → ne PAS confondre avec le numéro de BL
- Remises professionnelles possibles (prix barré / prix remisé) → utiliser le prix FINAL (après remise)
- Multi-TVA : 5,5% (alimentaire), 10% (restauration), 20% (non alimentaire)
- Unités courantes : KG, PU, PCK (pack), BOT, CAR, BTE
- Le montant ligne est parfois TTC (vérifier l'en-tête de colonne)

Totaux en bas :
- Récapitulatif par taux TVA : Base HT | TVA | TTC
- total_ht = somme des bases HT de tous les taux TVA
- Ne PAS confondre le numéro de carte Metro ou le n° de client avec un montant
CTX;
    }

    private function getTransgourmetContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — TRANSGOURMET (ex-Promocash, groupe Coop) :

Distribution généraliste CHR (Cafés, Hôtels, Restaurants).

Numéro BL : format 10 chiffres, parfois préfixé "BL" ou "N° Livraison".

Le tableau produits contient ces colonnes :
Réf. | Désignation | Qté Cde | Qté Liv | UVC | PU HT | Montant HT

Spécificités :
- "Qté Cde" = quantite commandée
- "Qté Liv" = quantite_livree (si absente, utiliser Qté Cde)
- "UVC" = Unité de Vente Consommateur → c'est l'unité de facturation (unite_facturation)
- Des remises en cascade sont possibles (remise 1 + remise 2 sur une même ligne)
- Si remise présente : total_ht_ligne = (qté × PU) - remises
- Produits parfois regroupés par famille/rayon (en-tête de section)
- Code article Transgourmet = 6-8 chiffres

Totaux en bas :
- Total HT, Total TVA (ventilé par taux), Total TTC
- Éventuellement franco de port ou frais de livraison → inclure dans total_ht si c'est une ligne produit
- total_ht = somme des montants HT lignes (hors frais de port séparés)
CTX;
    }

    private function getSyscoContext(): string
    {
        return <<<'CTX'
STRUCTURE SPÉCIFIQUE — SYSCO FRANCE (ex-Davigel pour surgelés) :

Distribution alimentaire multi-gamme (frais, surgelés, épicerie).

Numéro BL : format 8-10 chiffres, similaire à Brake (même groupe).

Le tableau produits contient ces colonnes :
Code | Libellé / Désignation | Qté Cde | Qté Liv | Unité | PU | Montant

Spécificités :
- "Qté Cde" = quantite commandée
- "Qté Liv" = quantite_livree (réellement livrée, peut différer de la commande)
- Numéro de tournée en en-tête → ne PAS confondre avec le numéro de BL
- Informations DLC / N° Lot parfois en sous-ligne
- Unités courantes : KG, PU, CAR (carton), PCK (pack), BTE (boîte), SAC
- Les produits surgelés (ex-Davigel) ont parfois un code température

Totaux en bas :
- Total HT, Total TVA, Total TTC
- total_ht = somme des montants HT lignes
- Ne PAS confondre le code tournée ou le code chauffeur avec un montant
CTX;
    }

    /**
     * Parse la réponse JSON de Claude.
     */
    private function parseResponse(string $jsonResponse): ?array
    {
        // Log only at debug level to avoid persisting potentially sensitive OCR content (product names, prices) in production log aggregators.
        $this->logger->debug('Réponse brute OCR (full)', [
            'length' => strlen($jsonResponse),
            'content' => mb_substr($jsonResponse, 0, 500) . (strlen($jsonResponse) > 500 ? '…[truncated]' : ''),
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

        if (!isset($data['lignes']) || !\is_array($data['lignes']) || $data['lignes'] === []) {
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
        if ($totalHt !== null) {
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
     * Si le BL n'a pas de fournisseur, tente de le résoudre depuis le nom extrait par OCR.
     */
    private function updateFournisseurInfo(BonLivraison $bl, array $data): void
    {
        if (!isset($data['fournisseur'])) {
            return;
        }

        $fournisseurData = $data['fournisseur'];

        // Si le BL n'a pas de fournisseur, essayer de le résoudre depuis le nom extrait
        if ($bl->getFournisseur() === null && !empty($fournisseurData['nom'])) {
            $fournisseur = $this->resolveFournisseurFromName($bl, $fournisseurData['nom']);
            if ($fournisseur !== null) {
                $bl->setFournisseur($fournisseur);
                $this->logger->info('Fournisseur resolu depuis OCR', [
                    'bl_id' => $bl->getId(),
                    'nom_extrait' => $fournisseurData['nom'],
                    'fournisseur_id' => $fournisseur->getId(),
                ]);
            } else {
                $this->logger->warning('Fournisseur non resolu depuis OCR', [
                    'bl_id' => $bl->getId(),
                    'nom_extrait' => $fournisseurData['nom'],
                ]);
            }
        }

        $fournisseur = $bl->getFournisseur();
        if ($fournisseur === null) {
            return;
        }

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
     * Résout un fournisseur depuis le nom extrait par OCR en cherchant parmi les fournisseurs de l'organisation.
     */
    private function resolveFournisseurFromName(BonLivraison $bl, string $nomExtrait): ?Fournisseur
    {
        $etablissement = $bl->getEtablissement();
        if ($etablissement === null) {
            return null;
        }

        $organisation = $etablissement->getOrganisation();
        if ($organisation === null) {
            return null;
        }

        $fournisseurs = $this->fournisseurRepository->findByOrganisation($organisation);

        // Use normalizeName() for consistent accent/punctuation stripping (same as prompt context selection)
        $nomExtraitNorm = self::normalizeName($nomExtrait);

        // Exact match first (normalized)
        foreach ($fournisseurs as $fournisseur) {
            if (self::normalizeName($fournisseur->getNom()) === $nomExtraitNorm) {
                return $fournisseur;
            }
        }

        // Partial match: OCR name contains DB name or vice versa.
        // Guard against very short DB names (< 4 chars) to avoid false positives
        // e.g. "Metro" matching inside "Métropole Distribution SARL".
        foreach ($fournisseurs as $fournisseur) {
            $nomDbNorm = self::normalizeName($fournisseur->getNom());
            if (strlen($nomDbNorm) >= 4 && (str_contains($nomExtraitNorm, $nomDbNorm) || str_contains($nomDbNorm, $nomExtraitNorm))) {
                return $fournisseur;
            }
        }

        return null;
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
     * @param array<string, Unite> $uniteCache
     *
     * @return LigneBonLivraison[]
     */
    private function mapLignes(array $data, BonLivraison $bl, array &$produitsNonMatches, array &$uniteCache): array
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
            $unite = $this->resolveUnite($uniteFactStr, $uniteCache);
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

    /**
     * Résout une unité depuis une chaîne extraite.
     * Le cache est local à chaque appel de mapLignes (passé par référence) pour éviter
     * tout état partagé entre extractions concurrentes dans les workers Messenger.
     *
     * @param array<string, Unite> $cache
     */
    private function resolveUnite(string $uniteStr, array &$cache): Unite
    {
        // Normalize via UnitNormalizer (canonical uppercase codes)
        $code = UnitNormalizer::normalize($uniteStr);
        if ($code === '') {
            $code = 'PU';
        }

        // Retourner depuis le cache si déjà résolu
        if (isset($cache[$code])) {
            return $cache[$code];
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

        $cache[$code] = $unite;

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
