<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use App\DTO\BonLivraisonMappingResult;
use App\Entity\BonLivraison;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\Unite;
use App\Enum\TypeUnite;
use App\Repository\FournisseurRepository;
use App\Repository\UniteRepository;
use App\Service\Unit\UnitNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BonLivraisonMapper
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FournisseurRepository $fournisseurRepository,
        private readonly UniteRepository $uniteRepository,
        private readonly OcrMatchingService $ocrMatchingService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Met à jour les informations du fournisseur et du BL depuis les données OCR.
     * À appeler AVANT la vérification anti-doublon.
     */
    public function mapHeader(BonLivraison $bl, array $data): void
    {
        $this->updateFournisseurInfo($bl, $data);
        $this->updateBonLivraisonInfo($bl, $data);
    }

    /**
     * Mappe les lignes extraites par OCR vers des entités LigneBonLivraison.
     * À appeler APRÈS la vérification anti-doublon.
     */
    public function mapLines(BonLivraison $bl, array $data): BonLivraisonMappingResult
    {
        $produitsNonMatches = [];
        $uniteCache = [];
        $lignes = $this->mapLignes($data, $bl, $produitsNonMatches, $uniteCache);

        return new BonLivraisonMappingResult(
            lignes: $lignes,
            produitsNonMatches: $produitsNonMatches,
        );
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

        // Use normalizeName() for consistent accent/punctuation stripping
        $nomExtraitNorm = BonLivraisonExtractorService::normalizeName($nomExtrait);

        // Exact match first (normalized)
        foreach ($fournisseurs as $fournisseur) {
            if (BonLivraisonExtractorService::normalizeName($fournisseur->getNom()) === $nomExtraitNorm) {
                return $fournisseur;
            }
        }

        // Partial match: OCR name contains DB name or vice versa.
        // Guard against very short DB names (< 4 chars) to avoid false positives
        foreach ($fournisseurs as $fournisseur) {
            $nomDbNorm = BonLivraisonExtractorService::normalizeName($fournisseur->getNom());
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
     *
     * @param array<string, Unite> $cache
     */
    private function resolveUnite(string $uniteStr, array &$cache): Unite
    {
        $code = UnitNormalizer::normalize($uniteStr);
        if ($code === '') {
            $code = 'PU';
        }

        if (isset($cache[$code])) {
            return $cache[$code];
        }

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
}
