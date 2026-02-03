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
        'kg' => 'kg',
        'kilo' => 'kg',
        'kilogramme' => 'kg',
        'kilogrammes' => 'kg',
        'g' => 'g',
        'gr' => 'g',
        'gramme' => 'g',
        'grammes' => 'g',
        'l' => 'L',
        'litre' => 'L',
        'litres' => 'L',
        'cl' => 'cL',
        'centilitre' => 'cL',
        'centilitres' => 'cL',
        'ml' => 'mL',
        'millilitre' => 'mL',
        'millilitres' => 'mL',
        'p' => 'p',
        'pc' => 'p',
        'pce' => 'p',
        'piece' => 'p',
        'pièce' => 'p',
        'pieces' => 'p',
        'pièces' => 'p',
        'u' => 'p',
        'unite' => 'p',
        'unité' => 'p',
        'unites' => 'p',
        'unités' => 'p',
        'bq' => 'bq',
        'barquette' => 'bq',
        'barquettes' => 'bq',
        'bt' => 'bt',
        'bouteille' => 'bt',
        'bouteilles' => 'bt',
        'ct' => 'ct',
        'carton' => 'ct',
        'cartons' => 'ct',
        'crt' => 'ct',
        'lot' => 'lot',
        'lots' => 'lot',
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

            // 2. Appeler l'API Claude
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
Tu es un assistant spécialisé dans l'extraction de données de bons de livraison pour la restauration.

Analyse cette image de bon de livraison et extrais TOUTES les informations dans le format JSON suivant.
Sois extrêmement précis sur les chiffres (quantités, prix, totaux).

Réponds UNIQUEMENT avec du JSON valide, sans aucun texte avant ou après.

Format attendu :
{
    "fournisseur": {
        "nom": "Nom du fournisseur",
        "adresse": "Adresse complète ou null",
        "telephone": "Téléphone ou null",
        "email": "Email ou null",
        "siret": "SIRET/SIREN ou null"
    },
    "bon_livraison": {
        "numero_bl": "Numéro du BL ou null",
        "numero_commande": "Numéro de commande ou null",
        "date_livraison": "YYYY-MM-DD",
        "client": "Nom du client destinataire"
    },
    "lignes": [
        {
            "code_produit": "Code article fournisseur ou null",
            "designation": "Désignation complète du produit",
            "quantite_commandee": 0.0,
            "quantite_livree": 0.0,
            "unite": "kg|g|L|cL|mL|p|bq|bt|ct|lot",
            "prix_unitaire": 0.0,
            "total_ligne": 0.0
        }
    ],
    "total_ht": 0.0,
    "nombre_lignes": 0,
    "confiance": "haute|moyenne|basse",
    "remarques": ["Liste de remarques ou difficultés rencontrées lors de l'extraction"]
}

Règles importantes :
- Les quantités et prix doivent être des nombres décimaux (pas de texte)
- L'unité doit être normalisée parmi : kg, g, L, cL, mL, p (pièce), bq (barquette), bt (bouteille), ct (carton), lot
- Si une valeur est illisible, mets null et ajoute une remarque
- Si le BL a plusieurs pages, extrais tout ce qui est visible
- Le champ "confiance" indique ta confiance globale dans l'extraction
- Vérifie que quantite_livree × prix_unitaire ≈ total_ligne (signale les incohérences)
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

        // Vérifier les champs obligatoires
        if (empty($data['fournisseur']['nom'])) {
            $warnings[] = 'Nom du fournisseur non détecté';
        }

        if (empty($data['lignes']) || !is_array($data['lignes'])) {
            $warnings[] = 'Aucune ligne de produit détectée';

            return $warnings;
        }

        // Vérifier la cohérence des totaux de ligne
        foreach ($data['lignes'] as $index => $ligne) {
            if (isset($ligne['quantite_livree'], $ligne['prix_unitaire'], $ligne['total_ligne'])) {
                $calculatedTotal = $ligne['quantite_livree'] * $ligne['prix_unitaire'];
                $difference = abs($calculatedTotal - $ligne['total_ligne']);

                // Tolérance de 0.05€ pour les arrondis
                if ($difference > 0.05) {
                    $warnings[] = sprintf(
                        'Ligne %d: total calculé (%.2f) différent du total lu (%.2f)',
                        $index + 1,
                        $calculatedTotal,
                        $ligne['total_ligne']
                    );
                }
            }
        }

        // Vérifier le total général
        if (isset($data['total_ht']) && !empty($data['lignes'])) {
            $calculatedTotal = array_sum(array_column($data['lignes'], 'total_ligne'));
            $difference = abs($calculatedTotal - $data['total_ht']);

            if ($difference > 0.10) {
                $warnings[] = sprintf(
                    'Total HT calculé (%.2f) différent du total lu (%.2f)',
                    $calculatedTotal,
                    $data['total_ht']
                );
            }
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
        if (!isset($data['bon_livraison'])) {
            return;
        }

        $blData = $data['bon_livraison'];

        if (empty($bl->getNumeroBl()) && !empty($blData['numero_bl'])) {
            $bl->setNumeroBl($blData['numero_bl']);
        }
        if (empty($bl->getNumeroCommande()) && !empty($blData['numero_commande'])) {
            $bl->setNumeroCommande($blData['numero_commande']);
        }
        if ($bl->getDateLivraison() === null && !empty($blData['date_livraison'])) {
            try {
                $bl->setDateLivraison(new \DateTimeImmutable($blData['date_livraison']));
            } catch (\Exception) {
                // Date invalide, ignorer
            }
        }

        if (isset($data['total_ht'])) {
            $bl->setTotalHt((string) $data['total_ht']);
        }
    }

    /**
     * Mappe les lignes extraites vers des entités LigneBonLivraison.
     *
     * @param string[] $produitsNonMatches Liste des codes produits non matchés (passée par référence)
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

            // Code produit et désignation
            $ligne->setCodeProduitBl($ligneData['code_produit'] ?? null);
            $ligne->setDesignationBl($ligneData['designation'] ?? 'Produit inconnu');

            // Quantités
            $ligne->setQuantiteCommandee(
                isset($ligneData['quantite_commandee']) ? (string) $ligneData['quantite_commandee'] : null
            );
            $ligne->setQuantiteLivree(
                (string) ($ligneData['quantite_livree'] ?? 0)
            );

            // Unité
            $unite = $this->resolveUnite($ligneData['unite'] ?? 'p');
            $ligne->setUnite($unite);

            // Prix
            $ligne->setPrixUnitaire((string) ($ligneData['prix_unitaire'] ?? 0));
            $ligne->setTotalLigne((string) ($ligneData['total_ligne'] ?? 0));

            // Tenter de matcher le produit fournisseur
            $produitFournisseur = null;
            if ($fournisseur !== null && !empty($ligneData['code_produit'])) {
                $produitFournisseur = $this->matchProduitFournisseur(
                    $ligneData['code_produit'],
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
