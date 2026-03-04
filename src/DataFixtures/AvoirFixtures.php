<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AvoirFournisseur;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\LigneAvoir;
use App\Entity\Utilisateur;
use App\Enum\MotifAvoir;
use App\Enum\StatutAvoir;
use App\Enum\StatutBonLivraison;
use App\Enum\TypeAlerte;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AvoirFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public static function getGroups(): array
    {
        return ['mercuriale'];
    }

    public function getDependencies(): array
    {
        return [MercurialeFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Find existing entities from MercurialeFixtures
        $admin = $manager->getRepository(Utilisateur::class)->findOneBy(['email' => 'admin@guinguette.fr']);
        $etab = $manager->getRepository(Etablissement::class)->findOneBy(['nom' => 'Guinguette du Château']);
        $terraAzur = $manager->getRepository(Fournisseur::class)->findOneBy(['code' => '3010614324970']);
        $leBihan = $manager->getRepository(Fournisseur::class)->findOneBy(['code' => 'BIHAN']);

        if (!$admin || !$etab || !$terraAzur || !$leBihan) {
            return;
        }

        // Find BLs with alerts and set them to ANOMALIE
        $bls = $manager->getRepository(BonLivraison::class)->findAll();
        $blWithAlerts = [];
        foreach ($bls as $bl) {
            foreach ($bl->getLignes() as $ligne) {
                if ($ligne->getAlertes()->count() > 0) {
                    $bl->setStatut(StatutBonLivraison::ANOMALIE);
                    $blWithAlerts[] = $bl;
                    break;
                }
            }
        }

        // Avoir 1 — DEMANDE (TerreAzur BL2 — échalion + courgette alerts)
        $bl2 = $this->findBlByNumero($blWithAlerts, '7830900916');
        if ($bl2) {
            $avoir1 = $this->createAvoir(
                $manager,
                $terraAzur,
                $etab,
                $bl2,
                $admin,
                StatutAvoir::DEMANDE,
                MotifAvoir::ECART_PRIX,
                new \DateTimeImmutable('2025-09-06'),
                'Écarts de prix constatés sur échalion et courgette, demande d\'avoir envoyée à TerreAzur.',
            );
            $this->addLignesFromBl($avoir1, $bl2);
            $manager->persist($avoir1);
        }

        // Avoir 2 — RECU (Le Bihan BL5 — prosecco alert)
        $bl5 = $this->findBlByNumero($blWithAlerts, '00211162');
        if ($bl5) {
            $avoir2 = $this->createAvoir(
                $manager,
                $leBihan,
                $etab,
                $bl5,
                $admin,
                StatutAvoir::RECU,
                MotifAvoir::ECART_PRIX,
                new \DateTimeImmutable('2025-08-14'),
                'Avoir reçu du fournisseur Le Bihan pour le prosecco surfacturé.',
            );
            $avoir2->setReference('AV-LB-2025-0042');
            $avoir2->setRecuLe(new \DateTimeImmutable('2025-08-20'));
            $avoir2->setValidatedBy($admin);
            $this->addLignesFromBl($avoir2, $bl5);
            // Override montants with actual avoir values
            $avoir2->setMontantHt('3.34');
            $avoir2->setMontantTva('0.67');
            $avoir2->setMontantTtc('4.01');
            $manager->persist($avoir2);
        }

        // Avoir 3 — IMPUTE (TerreAzur — standalone, retour marchandise)
        $avoir3 = $this->createAvoir(
            $manager,
            $terraAzur,
            $etab,
            null,
            $admin,
            StatutAvoir::IMPUTE,
            MotifAvoir::RETOUR_MARCHANDISE,
            new \DateTimeImmutable('2025-07-15'),
            'Retour de dorade royale — produit avarié à réception.',
        );
        $avoir3->setReference('AV-TA-2025-0018');
        $avoir3->setRecuLe(new \DateTimeImmutable('2025-07-22'));
        $avoir3->setImputeLe(new \DateTimeImmutable('2025-07-25'));
        $avoir3->setValidatedBy($admin);
        $avoir3->setMontantHt('120.27');
        $avoir3->setMontantTva('6.61');
        $avoir3->setMontantTtc('126.88');

        $ligne = new LigneAvoir();
        $ligne->setDesignation('Ft dorade roy el 130/180 AP gr SA 20P');
        $ligne->setQuantite('6.000');
        $ligne->setPrixUnitaire('20.0450');
        $ligne->setMontantLigne('120.27');
        $avoir3->addLigne($ligne);
        $manager->persist($avoir3);

        // Avoir 4 — ANNULE (Le Bihan — geste commercial annulé)
        $avoir4 = $this->createAvoir(
            $manager,
            $leBihan,
            $etab,
            null,
            $admin,
            StatutAvoir::ANNULE,
            MotifAvoir::GESTE_COMMERCIAL,
            new \DateTimeImmutable('2025-08-01'),
            'Geste commercial demandé mais refusé par le fournisseur — annulé.',
        );
        $avoir4->setMontantHt('25.00');

        $ligne2 = new LigneAvoir();
        $ligne2->setDesignation('Geste commercial sur commande août');
        $ligne2->setQuantite('1.000');
        $ligne2->setPrixUnitaire('25.0000');
        $ligne2->setMontantLigne('25.00');
        $avoir4->addLigne($ligne2);
        $manager->persist($avoir4);

        $manager->flush();
    }

    private function createAvoir(
        ObjectManager $manager,
        Fournisseur $fournisseur,
        Etablissement $etablissement,
        ?BonLivraison $bl,
        Utilisateur $createdBy,
        StatutAvoir $statut,
        MotifAvoir $motif,
        \DateTimeImmutable $demandeLe,
        string $commentaire,
    ): AvoirFournisseur {
        $avoir = new AvoirFournisseur();
        $avoir->setFournisseur($fournisseur);
        $avoir->setEtablissement($etablissement);
        $avoir->setBonLivraison($bl);
        $avoir->setStatut($statut);
        $avoir->setMotif($motif);
        $avoir->setDemandeLe($demandeLe);
        $avoir->setCreatedBy($createdBy);
        $avoir->setCommentaire($commentaire);

        return $avoir;
    }

    private function addLignesFromBl(AvoirFournisseur $avoir, BonLivraison $bl): void
    {
        $totalHt = '0';
        foreach ($bl->getLignes() as $ligne) {
            foreach ($ligne->getAlertes() as $alerte) {
                if (\in_array($alerte->getTypeAlerte(), [TypeAlerte::ECART_PRIX, TypeAlerte::ECART_QUANTITE], true)) {
                    $prixBl = $ligne->getPrixUnitaire();
                    $prixMercuriale = $alerte->getValeurAttendue();
                    $ecartUnitaire = $prixBl !== null && $prixMercuriale !== null
                        ? bcsub($prixBl, $prixMercuriale, 4)
                        : '0';
                    if (bccomp($ecartUnitaire, '0', 4) < 0) {
                        $ecartUnitaire = bcmul($ecartUnitaire, '-1', 4);
                    }
                    $quantite = $ligne->getQuantiteLivree() ?? '0';
                    $montantLigne = bcmul($quantite, $ecartUnitaire, 2);

                    $ligneAvoir = new LigneAvoir();
                    $ligneAvoir->setDesignation($ligne->getDesignationBl() ?? '');
                    $ligneAvoir->setQuantite($quantite);
                    $ligneAvoir->setPrixUnitaire($ecartUnitaire);
                    $ligneAvoir->setMontantLigne($montantLigne);

                    $pf = $ligne->getProduitFournisseur();
                    if ($pf !== null) {
                        $ligneAvoir->setProduit($pf->getProduit());
                    }

                    $avoir->addLigne($ligneAvoir);
                    $totalHt = bcadd($totalHt, $montantLigne, 2);
                    break;
                }
            }
        }
        $avoir->setMontantHt($totalHt);
    }

    /**
     * @param BonLivraison[] $bls
     */
    private function findBlByNumero(array $bls, string $numero): ?BonLivraison
    {
        foreach ($bls as $bl) {
            if ($bl->getNumeroBl() === $numero) {
                return $bl;
            }
        }

        return null;
    }
}
