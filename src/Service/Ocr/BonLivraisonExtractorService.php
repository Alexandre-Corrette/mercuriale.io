<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\DTO\ExtractionResult;
use App\Entity\BonLivraison;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\UniteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BonLivraisonExtractorService
{
    private const UNITE_MAPPING = [
        // Poids
        'kg' => 'kg',
        'kilo' => 'kg',
        'kilogramme' => 'kg',
        'kilogrammes' => 'kg',
        'g' => 'g',
        'gr' => 'g',
        'gramme' => 'g',
        'grammes' => 'g',
        // Volume
        'l' => 'L',
        'litre' => 'L',
        'litres' => 'L',
        'cl' => 'cL',
        'centilitre' => 'cL',
        'centilitres' => 'cL',
        'ml' => 'mL',
        'millilitre' => 'mL',
        'millilitres' => 'mL',
        // Pièce / unité
        'p' => 'p',
        'pc' => 'p',
        'pce' => 'p',
        'piece' => 'p',
        'pièce' => 'p',
        'pieces' => 'p',
        'pièces' => 'p',
        'u' => 'p',
        'pu' => 'p',
        'unite' => 'p',
        'unité' => 'p',
        'unites' => 'p',
        'unités' => 'p',
        // Conditionnements
        'bq' => 'bq',
        'bqt' => 'bq',
        'barquette' => 'bq',
        'barquettes' => 'bq',
        'bt' => 'bt',
        'bot' => 'bt',
        'bouteille' => 'bt',
        'bouteilles' => 'bt',
        'ct' => 'ct',
        'carton' => 'ct',
        'cartons' => 'ct',
        'crt' => 'ct',
        'col' => 'col',
        'colis' => 'col',
        'flt' => 'flt',
        'filet' => 'flt',
        'sac' => 'sac',
        'lot' => 'lot',
        'lots' => 'lot',
        'car' => 'ct',
        'caisse' => 'ct',
    ];

    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProduitFournisseurRepository $produitFournisseurRepository,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly UniteRepository $uniteRepository,
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
Tu es un expert en lecture de bons de livraison (BL) pour la restauration professionnelle.

ÉTAPE 1 — ANALYSE DU DOCUMENT

Avant d'extraire les données, identifie :
- Le fournisseur et son secteur (fruits/légumes, marée, viande, boissons, épicerie...)
- La structure exacte des colonnes du tableau (elles varient selon les fournisseurs)
- Les colonnes de quantité : certains BL ont une "quantité livrée" (nombre de colis/pièces) ET une "quantité facturée" (poids ou volume réel). C'est la QUANTITÉ FACTURÉE qui sert au calcul du prix.
- La présence éventuelle de colonnes de majoration/décote (MJ, DECOL, ajustement...)
- L'unité de facturation (UF) qui est l'unité utilisée pour le prix unitaire

ÉTAPE 2 — EXTRACTION
Extrais TOUTES les lignes dans le JSON ci-dessous.

RÈGLES CRITIQUES :
- Le "prix_unitaire" est TOUJOURS celui associé à l'unité de facturation (€/kg, €/pièce, €/colis...)
- La "quantite_facturee" est TOUJOURS la quantité de facturation (celle utilisée pour le calcul du total), PAS la quantité de colis livrés
- L'"unite_facturation" est TOUJOURS l'unité de facturation (kg, p, L...), PAS l'unité de livraison (COL, FLT, CAR...)
- Le "total_ht_ligne" est le montant HT final de la ligne TEL QU'IMPRIMÉ sur le BL (il peut inclure des majorations/décotes)
- VÉRIFICATION : quantite_facturee × prix_unitaire doit être PROCHE du total_ht_ligne (l'écart éventuel = majoration/décote)
- Les nombres décimaux utilisent le POINT comme séparateur (pas la virgule)
- Si une valeur est illisible, mets null

Réponds UNIQUEMENT avec du JSON valide, sans texte avant ou après.

{
    "fournisseur": {
        "nom": "Nom commercial du fournisseur",
        "groupe": "Groupe/maison mère si visible (ex: Pomona, Brake, Sysco) ou null",
        "adresse": "Adresse ou null",
        "telephone": "Téléphone ou null",
        "siret": "SIRET ou null"
    },
    "document": {
        "type": "BL",
        "numero": "Numéro du bon de livraison",
        "numero_commande": "Numéro de commande ou null",
        "date": "YYYY-MM-DD",
        "client": "Nom du client destinataire",
        "page": "ex: 1/2 ou null si non visible"
    },
    "colonnes_detectees": ["Liste des en-têtes de colonnes tels que lus sur le document"],
    "lignes": [
        {
            "numero_ligne": 100,
            "code_produit": "Code article fournisseur",
            "designation": "Désignation EXACTE telle qu'imprimée, ne rien inventer",
            "origine": "Pays/code origine si indiqué (FR, ES, MA...) ou null",
            "quantite_livree": 3.0,
            "unite_livraison": "PU|COL|FLT|CAR|BOT|SAC|BQT|KG|...",
            "quantite_facturee": 4.35,
            "unite_facturation": "kg|p|L|bot|sac|bqt|...",
            "prix_unitaire": 1.99,
            "majoration_decote": 0.0,
            "total_ht_ligne": 8.66,
            "tva_code": "F 1|M 1|... tel qu'imprimé ou null"
        }
    ],
    "totaux": {
        "nombre_colis": 10,
        "poids_total_kg": 80.501,
        "total_ht": null
    },
    "confiance": "haute|moyenne|basse",
    "remarques": ["Difficultés rencontrées, valeurs incertaines, incohérences détectées"]
}

EXEMPLES DE PIÈGES COURANTS :
- "3,000 PU" avec "4,350 KG" signifie 3 pièces livrées mais 4.350 kg facturés → quantite_facturee = 4.35, unite_facturation = "kg"
- "1,000 COL" avec "5,000 KG" signifie 1 colis livré mais 5 kg facturés → quantite_facturee = 5.0, unite_facturation = "kg"
- "8,000 PU" avec "8,000 PU" signifie 8 pièces livrées ET facturées → quantite_facturee = 8.0, unite_facturation = "p"
- Un total de ligne qui ne correspond pas à quantite × prix = il y a probablement une majoration/décote
- Les virgules dans les nombres sur les BL français sont des séparateurs décimaux (1,990 = 1.99)
PROMPT;
    }

    /**
     * Parse la réponse JSON de Claude.
     */
    private function parseResponse(string $jsonResponse): ?array
    {
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

        $data = json_decode($jsonResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Erreur parsing JSON extraction', [
                'error' => json_last_error_msg(),
                'response_length' => strlen($jsonResponse),
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
     * @param string[] $produitsNonMatches
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
            $ligne->setNumeroLigneBl(isset($ligneData['numero_ligne']) ? (int) $ligneData['numero_ligne'] : null);
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
            $uniteFactStr = $ligneData['unite_facturation'] ?? 'p';
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

            // Matching produit fournisseur
            $produitFournisseur = null;
            if ($fournisseur !== null && !empty($ligneData['code_produit'])) {
                $produitFournisseur = $this->matchProduitFournisseur(
                    (string) $ligneData['code_produit'],
                    $fournisseur
                );

                if ($produitFournisseur === null) {
                    $produitsNonMatches[] = $ligneData['code_produit'];
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
     * Tente de matcher un code produit avec la base de données.
     */
    private function matchProduitFournisseur(string $codeProduit, Fournisseur $fournisseur): ?ProduitFournisseur
    {
        return $this->produitFournisseurRepository->findOneBy([
            'fournisseur' => $fournisseur,
            'codeFournisseur' => $codeProduit,
            'actif' => true,
        ]);
    }

    /**
     * Résout une unité depuis une chaîne extraite.
     */
    private function resolveUnite(string $uniteStr): Unite
    {
        $uniteStr = strtolower(trim($uniteStr));

        // Mapper vers le code standard
        $code = self::UNITE_MAPPING[$uniteStr] ?? 'p';

        // Chercher l'unité en base
        $unite = $this->uniteRepository->findOneBy(['code' => $code]);

        // Fallback sur pièce si non trouvée
        if ($unite === null) {
            $unite = $this->uniteRepository->findOneBy(['code' => 'p']);
        }

        // Créer l'unité pièce si elle n'existe pas
        if ($unite === null) {
            $unite = new Unite();
            $unite->setCode('p');
            $unite->setNom('Pièce');
            $this->entityManager->persist($unite);
        }

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
