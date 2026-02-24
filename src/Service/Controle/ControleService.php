<?php

declare(strict_types=1);

namespace App\Service\Controle;

use App\Entity\AlerteControle;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\LigneBonLivraison;
use App\Entity\Mercuriale;
use App\Entity\ProduitFournisseur;
use App\Enum\StatutBonLivraison;
use App\Enum\StatutControle;
use App\Enum\TypeAlerte;
use App\Repository\MercurialeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ControleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurialeRepository $mercurialeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lance le contrôle complet d'un bon de livraison.
     *
     * @return int Nombre d'alertes générées
     */
    public function controlerBonLivraison(BonLivraison $bl): int
    {
        $this->logger->info('Début contrôle BL', ['bl_id' => $bl->getId()]);

        $nombreAlertes = 0;

        foreach ($bl->getLignes() as $ligne) {
            $alertes = $this->controlerLigne($ligne, $bl);
            $nombreAlertes += count($alertes);

            // Mettre à jour le statut de contrôle de la ligne
            $this->updateStatutLigne($ligne, $alertes);
        }

        // Mettre à jour le statut du BL selon les alertes
        if ($nombreAlertes > 0) {
            $bl->setStatut(StatutBonLivraison::ANOMALIE);
        }

        $this->entityManager->flush();

        $this->logger->info('Fin contrôle BL', [
            'bl_id' => $bl->getId(),
            'nombre_alertes' => $nombreAlertes,
        ]);

        return $nombreAlertes;
    }

    /**
     * Contrôle une ligne individuelle et retourne les alertes générées.
     *
     * @return AlerteControle[]
     */
    private function controlerLigne(LigneBonLivraison $ligne, BonLivraison $bl): array
    {
        $alertes = [];

        // Supprimer les anciennes alertes non traitées
        $this->supprimerAnciennesAlertes($ligne);

        // 1. Contrôle produit connu
        $alerteProduitInconnu = $this->controlerProduitConnu($ligne);
        if ($alerteProduitInconnu !== null) {
            $alertes[] = $alerteProduitInconnu;
            // Si produit inconnu, on ne peut pas faire les autres contrôles
            return $alertes;
        }

        // 2. Contrôle quantité
        $alerteQuantite = $this->controlerQuantite($ligne);
        if ($alerteQuantite !== null) {
            $alertes[] = $alerteQuantite;
        }

        // 3. Contrôle prix mercuriale existe
        $alertePrixManquant = $this->controlerPrixExiste($ligne, $bl);
        if ($alertePrixManquant !== null) {
            $alertes[] = $alertePrixManquant;
        } else {
            // 4. Contrôle écart de prix (seulement si prix mercuriale existe)
            $alertePrix = $this->controlerPrix($ligne, $bl);
            if ($alertePrix !== null) {
                $alertes[] = $alertePrix;
            }
        }

        return $alertes;
    }

    /**
     * Supprime les alertes non traitées d'une ligne avant un nouveau contrôle.
     */
    private function supprimerAnciennesAlertes(LigneBonLivraison $ligne): void
    {
        foreach ($ligne->getAlertes() as $alerte) {
            if (!$alerte->isTraitee()) {
                $ligne->removeAlerte($alerte);
                $this->entityManager->remove($alerte);
            }
        }
    }

    /**
     * Vérifie si le produit est référencé dans la base.
     */
    private function controlerProduitConnu(LigneBonLivraison $ligne): ?AlerteControle
    {
        if ($ligne->getProduitFournisseur() !== null) {
            return null;
        }

        $alerte = new AlerteControle();
        $alerte->setLigneBl($ligne);
        $alerte->setTypeAlerte(TypeAlerte::PRODUIT_INCONNU);
        $alerte->setMessage(sprintf(
            'Produit non référencé : %s (code: %s)',
            $ligne->getDesignationBl(),
            $ligne->getCodeProduitBl() ?? 'aucun'
        ));

        $ligne->addAlerte($alerte);
        $this->entityManager->persist($alerte);

        return $alerte;
    }

    /**
     * Compare quantité commandée vs livrée.
     */
    private function controlerQuantite(LigneBonLivraison $ligne): ?AlerteControle
    {
        $qteCommandee = $ligne->getQuantiteCommandee();
        $qteLivree = $ligne->getQuantiteLivree();

        // Pas de contrôle si pas de quantité commandée
        if ($qteCommandee === null || $qteCommandee === '0') {
            return null;
        }

        $qteCommandeeFloat = (float) $qteCommandee;
        $qteLivreeFloat = (float) $qteLivree;

        // Pas d'écart
        if (abs($qteCommandeeFloat - $qteLivreeFloat) < 0.001) {
            return null;
        }

        $ecartPct = $this->calculerEcartPct($qteCommandeeFloat, $qteLivreeFloat);
        $unite = $ligne->getUnite()?->getCode() ?? '';

        $alerte = new AlerteControle();
        $alerte->setLigneBl($ligne);
        $alerte->setTypeAlerte(TypeAlerte::ECART_QUANTITE);
        $alerte->setValeurAttendue($qteCommandee);
        $alerte->setValeurRecue($qteLivree);
        $alerte->setEcartPct((string) $ecartPct);
        $alerte->setMessage(sprintf(
            'Livré %.3f %s au lieu de %.3f %s commandé (%+.1f%%)',
            $qteLivreeFloat,
            $unite,
            $qteCommandeeFloat,
            $unite,
            $ecartPct
        ));

        $ligne->addAlerte($alerte);
        $this->entityManager->persist($alerte);

        return $alerte;
    }

    /**
     * Vérifie si un prix mercuriale existe pour ce produit.
     */
    private function controlerPrixExiste(LigneBonLivraison $ligne, BonLivraison $bl): ?AlerteControle
    {
        $produitFournisseur = $ligne->getProduitFournisseur();
        if ($produitFournisseur === null) {
            return null; // Déjà géré par controlerProduitConnu
        }

        $etablissement = $bl->getEtablissement();
        $dateLivraison = $bl->getDateLivraison() ?? new \DateTimeImmutable();

        $mercuriale = $this->getPrixMercuriale($produitFournisseur, $etablissement, $dateLivraison);

        if ($mercuriale !== null) {
            return null;
        }

        $alerte = new AlerteControle();
        $alerte->setLigneBl($ligne);
        $alerte->setTypeAlerte(TypeAlerte::PRIX_MANQUANT);
        $alerte->setMessage(sprintf(
            'Aucun prix négocié trouvé pour %s à la date du %s',
            $produitFournisseur->getDesignationFournisseur(),
            $dateLivraison->format('d/m/Y')
        ));

        $ligne->addAlerte($alerte);
        $this->entityManager->persist($alerte);

        return $alerte;
    }

    /**
     * Compare le prix du BL avec le prix mercuriale.
     */
    private function controlerPrix(LigneBonLivraison $ligne, BonLivraison $bl): ?AlerteControle
    {
        $produitFournisseur = $ligne->getProduitFournisseur();
        if ($produitFournisseur === null) {
            return null;
        }

        $etablissement = $bl->getEtablissement();
        $dateLivraison = $bl->getDateLivraison() ?? new \DateTimeImmutable();

        $mercuriale = $this->getPrixMercuriale($produitFournisseur, $etablissement, $dateLivraison);
        if ($mercuriale === null) {
            return null; // Géré par controlerPrixExiste
        }

        $prixBl = (float) $ligne->getPrixUnitaire();
        $prixNegocie = $mercuriale->getPrixNegocieAsFloat();
        $seuilAlerte = $mercuriale->getSeuilAlertePctAsFloat();

        // Calculer l'écart en pourcentage
        $ecartPct = $this->calculerEcartPct($prixNegocie, $prixBl);

        // Vérifier si l'écart dépasse le seuil
        if (abs($ecartPct) <= $seuilAlerte) {
            return null;
        }

        $unite = $ligne->getUnite()?->getCode() ?? '';
        $sensEcart = $ecartPct > 0 ? 'supérieur' : 'inférieur';

        $alerte = new AlerteControle();
        $alerte->setLigneBl($ligne);
        $alerte->setTypeAlerte(TypeAlerte::ECART_PRIX);
        $alerte->setValeurAttendue((string) $prixNegocie);
        $alerte->setValeurRecue((string) $prixBl);
        $alerte->setEcartPct((string) $ecartPct);
        $alerte->setMessage(sprintf(
            'Prix facturé %.4f €/%s %s au prix négocié %.4f €/%s (%+.1f%%). Seuil d\'alerte : %.1f%%',
            $prixBl,
            $unite,
            $sensEcart,
            $prixNegocie,
            $unite,
            $ecartPct,
            $seuilAlerte
        ));

        $ligne->addAlerte($alerte);
        $this->entityManager->persist($alerte);

        return $alerte;
    }

    /**
     * Cherche le prix mercuriale applicable pour une ligne.
     * Priorité : prix établissement > prix groupe
     */
    private function getPrixMercuriale(
        ProduitFournisseur $produitFournisseur,
        ?Etablissement $etablissement,
        \DateTimeInterface $date,
    ): ?Mercuriale {
        // 1. Chercher un prix spécifique à l'établissement
        if ($etablissement !== null) {
            $mercuriale = $this->mercurialeRepository->findPrixValide(
                $produitFournisseur,
                $etablissement,
                $date
            );

            if ($mercuriale !== null) {
                return $mercuriale;
            }
        }

        // 2. Chercher un prix groupe (etablissement_id IS NULL)
        return $this->mercurialeRepository->findPrixValide(
            $produitFournisseur,
            null,
            $date
        );
    }

    /**
     * Calcule l'écart en pourcentage entre deux valeurs.
     */
    private function calculerEcartPct(float $attendu, float $recu): float
    {
        if (abs($attendu) < 0.0001) {
            return $recu > 0 ? 100.0 : 0.0;
        }

        return round((($recu - $attendu) / $attendu) * 100, 2);
    }

    /**
     * Met à jour le statut de contrôle d'une ligne selon les alertes.
     *
     * @param AlerteControle[] $alertes
     */
    private function updateStatutLigne(LigneBonLivraison $ligne, array $alertes): void
    {
        if (empty($alertes)) {
            $ligne->setStatutControle(StatutControle::OK);

            return;
        }

        $hasEcartQuantite = false;
        $hasEcartPrix = false;

        foreach ($alertes as $alerte) {
            if ($alerte->getTypeAlerte() === TypeAlerte::ECART_QUANTITE) {
                $hasEcartQuantite = true;
            }
            if ($alerte->getTypeAlerte() === TypeAlerte::ECART_PRIX) {
                $hasEcartPrix = true;
            }
        }

        if ($hasEcartQuantite && $hasEcartPrix) {
            $ligne->setStatutControle(StatutControle::ECART_MULTIPLE);
        } elseif ($hasEcartQuantite) {
            $ligne->setStatutControle(StatutControle::ECART_QTE);
        } elseif ($hasEcartPrix) {
            $ligne->setStatutControle(StatutControle::ECART_PRIX);
        } else {
            // Produit inconnu ou prix manquant
            $ligne->setStatutControle(StatutControle::NON_CONTROLE);
        }
    }
}
