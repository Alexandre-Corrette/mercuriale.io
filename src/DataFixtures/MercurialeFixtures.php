<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AlerteControle;
use App\Entity\BonLivraison;
use App\Entity\CategorieProduit;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\Mercuriale;
use App\Entity\Organisation;
use App\Entity\OrganisationFournisseur;
use App\Entity\Produit;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use App\Entity\UtilisateurOrganisation;
use App\Enum\StatutBonLivraison;
use App\Enum\StatutControle;
use App\Enum\TypeAlerte;
use App\Enum\TypeUnite;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MercurialeFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    private ObjectManager $manager;

    /** @var array<string, Unite> */
    private array $unites = [];

    /** @var array<string, CategorieProduit> */
    private array $categories = [];

    /** @var array<string, ProduitFournisseur> */
    private array $produitsFournisseur = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $this->manager = $manager;

        // Get entities from AppFixtures references
        /** @var Organisation $org */
        $org = $this->getReference('org-escale', Organisation::class);
        /** @var Etablissement $etab */
        $etab = $this->getReference('etab-guinguette', Etablissement::class);
        /** @var Utilisateur $admin */
        $admin = $this->getReference('user-admin-escale', Utilisateur::class);

        // Unités & catégories (ensure extras needed for mercuriale)
        $this->ensureUnites();
        $this->ensureCategories();

        // Fournisseurs + catalogues + mercuriale
        $terraAzur = $this->createTerreAzur($org, $etab, $admin);
        $leBihan = $this->createLeBihan($org, $etab, $admin);

        $manager->flush();

        // BL + lignes + alertes
        $this->createBL1TerreAzur($etab, $terraAzur, $admin);
        $this->createBL2TerreAzur($etab, $terraAzur, $admin);
        $this->createBL3TerreAzur($etab, $terraAzur, $admin);
        $this->createBL4LeBihan($etab, $leBihan, $admin);
        $this->createBL5LeBihan($etab, $leBihan, $admin);

        // Compte demo — gérant Guinguette uniquement
        $demo = new Utilisateur();
        $demo->setOrganisation($org);
        $demo->setEmail('demo@mercuriale.io');
        $demo->setNom('Demo');
        $demo->setPrenom('Utilisateur');
        $demo->setRoles(['ROLE_GERANT']);
        $demo->setPassword($this->passwordHasher->hashPassword($demo, 'demo2026'));
        $demo->setActif(true);
        $manager->persist($demo);

        $uo = new UtilisateurOrganisation();
        $uo->setUtilisateur($demo);
        $uo->setOrganisation($org);
        $uo->setRole('member');
        $manager->persist($uo);

        $ue = new UtilisateurEtablissement();
        $ue->setUtilisateur($demo);
        $ue->setEtablissement($etab);
        $ue->setRole('ROLE_GERANT');
        $manager->persist($ue);

        $manager->flush();
    }

    // ─── Unités ─────────────────────────────────────────────────

    private function ensureUnites(): void
    {
        $needed = [
            'KG' => ['nom' => 'Kilogramme', 'type' => TypeUnite::POIDS],
            'L' => ['nom' => 'Litre', 'type' => TypeUnite::VOLUME],
            'COL' => ['nom' => 'Colis', 'type' => TypeUnite::QUANTITE],
            'BOT' => ['nom' => 'Botte', 'type' => TypeUnite::QUANTITE],
            'BQT' => ['nom' => 'Barquette', 'type' => TypeUnite::QUANTITE],
            'SAC' => ['nom' => 'Sac', 'type' => TypeUnite::QUANTITE],
            'FUT' => ['nom' => 'Fût', 'type' => TypeUnite::VOLUME],
            'CAR' => ['nom' => 'Carton', 'type' => TypeUnite::QUANTITE],
            'PU' => ['nom' => 'Pièce unitaire', 'type' => TypeUnite::QUANTITE],
            'UNI' => ['nom' => 'Unité', 'type' => TypeUnite::QUANTITE],
        ];

        $repo = $this->manager->getRepository(Unite::class);
        $ordre = 20;

        foreach ($needed as $code => $data) {
            $unite = $repo->findOneBy(['code' => $code]);
            if (!$unite) {
                $unite = new Unite();
                $unite->setCode($code);
                $unite->setNom($data['nom']);
                $unite->setType($data['type']);
                $unite->setOrdre($ordre++);
                $this->manager->persist($unite);
            }
            $this->unites[$code] = $unite;
        }
    }

    // ─── Catégories ─────────────────────────────────────────────

    private function ensureCategories(): void
    {
        $needed = [
            'SALADES' => 'Salades',
            'LEGUMES' => 'Légumes',
            'HERBES' => 'Herbes aromatiques',
            'FLEURS' => 'Fleurs comestibles',
            'MAREE' => 'Marée',
            'FRUITS' => 'Fruits',
            'BIERES' => 'Bières',
            'SOFTS' => 'Softs & Jus',
            'EAUX' => 'Eaux',
            'SPIRITUEUX' => 'Spiritueux',
            'VINS' => 'Vins & Effervescents',
            'CONSO' => 'Consommables',
        ];

        $repo = $this->manager->getRepository(CategorieProduit::class);
        $ordre = 20;

        foreach ($needed as $code => $nom) {
            $cat = $repo->findOneBy(['code' => $code]);
            if (!$cat) {
                $cat = new CategorieProduit();
                $cat->setCode($code);
                $cat->setNom($nom);
                $cat->setOrdre($ordre++);
                $this->manager->persist($cat);
            }
            $this->categories[$code] = $cat;
        }
    }

    // ─── TerreAzur ──────────────────────────────────────────────

    private function createTerreAzur(Organisation $org, Etablissement $etab, Utilisateur $admin): Fournisseur
    {
        $f = $this->findOrCreateFournisseur([
            'nom' => 'TerreAzur Aquitaine',
            'code' => '3010614324970',
            'siret' => '55204499200337',
            'adresse' => '110 Quai de Paludate CS71856',
            'codePostal' => '33080',
            'ville' => 'Bordeaux Cedex',
            'telephone' => '05 56 49 99 00',
        ]);

        $this->ensureOrganisationFournisseur($org, $f, '379212');
        $f->addEtablissement($etab);

        $catalogue = [
            ['103634', 'Salade jeunes pousses mélangées provençal ½ 500g x2 100% FR', 'KG', '7.1100', '5.5', 'SALADES'],
            ['104884', 'Pomme de terre Agata grenaille ct 12,5K c1 FR', 'KG', '2.6100', '5.5', 'LEGUMES'],
            ['106139', 'Échalion 30/50 5K c1 FR', 'KG', '3.0000', '5.5', 'LEGUMES'],
            ['106535', 'Oignon rouge 60/80 5K c1 FR', 'KG', '3.2400', '5.5', 'LEGUMES'],
            ['107990', 'Poivron rouge 90/110 5K c1 MA', 'KG', '2.8800', '5.5', 'LEGUMES'],
            ['110061', 'Menthe fraîche sac 100g MA', 'SAC', '1.9900', '5.5', 'HERBES'],
            ['112987', 'Fleur pensée 1bq FR', 'BQT', '7.1100', '5.5', 'FLEURS'],
            ['114412', 'Dorade royale élevage 130/180 AP gr SA 20P', 'KG', '20.0450', '5.5', 'MAREE'],
            ['120715', 'Groseille rouge bq 125g x12 c1 FR', 'BQT', '3.8700', '5.5', 'FRUITS'],
            ['149543', 'Oignon blanc cébette botte 15P FR', 'BOT', '1.6500', '5.5', 'LEGUMES'],
            ['151265', 'Citron lime 4,5K c1 BR', 'KG', '3.1000', '5.5', 'FRUITS'],
            ['160823', 'Tomate côtelée jaune 3,5K c2 FR', 'KG', '4.5000', '5.5', 'LEGUMES'],
            ['188800', 'Framboise bq 125g x12 c1 REG', 'BQT', '3.2500', '5.5', 'FRUITS'],
            ['189974', 'Avocat Hass 165/196g pad22F SelTA c1ZA', 'PU', '0.9000', '5.5', 'FRUITS'],
            ['189984', 'Avocat Hass 165/196g pad22F SelTA c1PE', 'PU', '0.9000', '5.5', 'FRUITS'],
            ['244337', 'Aubergine 300/400 HVE plt 5K B&E c1 REG', 'KG', '3.6000', '5.5', 'LEGUMES'],
            ['249586', 'Pomme Golden 170/200 CE2 1r 7K B&E c1 FR', 'KG', '2.3500', '5.5', 'FRUITS'],
            ['250113', 'Mûre bq 125g x8 Driscoll c1 PT', 'BQT', '3.8700', '5.5', 'FRUITS'],
            ['252870', 'Pomme de terre Agata 50+ CE2 10K c1 REG', 'KG', '0.9500', '5.5', 'LEGUMES'],
            ['273349', 'Courgette 14/21 HVE 5K c1 FR', 'KG', '1.9500', '5.5', 'LEGUMES'],
            ['319348', 'Fleur pensée jaune bq 25P IEG FR', 'BQT', '7.1100', '5.5', 'FLEURS'],
            ['319451', 'Fleur pensée bicolore et bleu bq 25P IEG FR', 'BQT', '7.1100', '5.5', 'FLEURS'],
            ['321600', 'Myrtille bq 125g x12 Driscoll c1 PE', 'BQT', '2.8800', '5.5', 'FRUITS'],
            ['209681', 'Fleur viola 1bq FR', 'BQT', '7.1100', '5.5', 'FLEURS'],
            ['151255', 'Citron lime 4,5K c1 BR (alt)', 'KG', '3.1000', '5.5', 'FRUITS'],
            ['107853', 'Concombre 400/500 12P c1 FR', 'KG', '2.7900', '5.5', 'LEGUMES'],
            ['108046', 'Poivron rouge 80/100 5K c1 NL', 'KG', '2.7900', '5.5', 'LEGUMES'],
            ['110270', 'Coriandre fraîche sac 100g FR', 'SAC', '1.9900', '5.5', 'HERBES'],
            ['142569', 'Salade cœur romaine flow 2pk1 c1 ES', 'SAC', '2.5000', '5.5', 'SALADES'],
            ['106529', 'Oignon rouge 60/80 5K c1 ES', 'KG', '3.1500', '5.5', 'LEGUMES'],
        ];

        foreach ($catalogue as $item) {
            $this->createProduitFournisseurAndMercuriale(
                $f, $etab, $admin,
                $item[0], $item[1], $item[2], $item[3], $item[4], $item[5],
            );
        }

        return $f;
    }

    // ─── Le Bihan TMEG ──────────────────────────────────────────

    private function createLeBihan(Organisation $org, Etablissement $etab, Utilisateur $admin): Fournisseur
    {
        $f = $this->findOrCreateFournisseur([
            'nom' => 'Le Bihan TMEG',
            'code' => 'BIHAN',
            'siret' => '43394813000011',
            'adresse' => 'Av Fernand Coin',
            'codePostal' => '33140',
            'ville' => 'Villenave d\'Ornon',
            'telephone' => '05 56 87 20 20',
        ]);

        $this->ensureOrganisationFournisseur($org, $f, '072575');
        $f->addEtablissement($etab);

        $catalogue = [
            ['012992', 'FUT 20L Eguzki Blanche', 'FUT', '4.1160', '20', 'BIERES'],
            ['016724', 'FUT 30L Tigre Bock 5°', 'FUT', '2.9190', '20', 'BIERES'],
            ['001306', 'FUT 20L Grimbergen Blonde', 'FUT', '4.6040', '20', 'BIERES'],
            ['001144', 'FUT 30L Limonade B Bordet', 'FUT', '1.1210', '5.5', 'SOFTS'],
            ['103820', '25CL Bud Tow Pac Insep VP', 'CAR', '1.0830', '20', 'BIERES'],
            ['002001', '25CL La French Ginger Beer VP', 'CAR', '1.1340', '20', 'SOFTS'],
            ['003042', '33CL Minibocal Granini Nectar Abricot VP', 'COL', '0.8110', '5.5', 'SOFTS'],
            ['002335', '33CL Boîte Coca Cola Slim x24', 'CAR', '0.7430', '5.5', 'SOFTS'],
            ['002355', '33CL Boîte Coca Sans Sucre Slim x24', 'CAR', '0.7730', '5.5', 'SOFTS'],
            ['009975', '33CL Boîte Fuzetea Pêche Intense Slim', 'CAR', '0.7680', '5.5', 'SOFTS'],
            ['007129', '100CL Pet Abatilles Bord. Pétillante x12', 'COL', '1.0350', '5.5', 'EAUX'],
            ['007127', '100CL Pet Abatilles Bord. Eau Plate x12', 'COL', '0.9060', '5.5', 'EAUX'],
            ['000019', '70CL Baileys 17° Irish Cream', 'COL', '8.1010', '20', 'SPIRITUEUX'],
            ['707702', '70CL Limoncello M Brizard 25°', 'COL', '9.3770', '20', 'SPIRITUEUX'],
            ['005111', 'Tube CO2 Orange 10KGS - L2P1', 'UNI', '51.3360', '20', 'CONSO'],
            ['001131', '70CL Cognac VS 40° Hennessy', 'COL', '25.6420', '20', 'SPIRITUEUX'],
            ['701625', '70CL Liqueur Mint\'s 15° MB', 'COL', '7.6140', '20', 'SPIRITUEUX'],
            ['706436', '70CL Liqueur Passion M Brizard 18°', 'COL', '8.9950', '20', 'SPIRITUEUX'],
            ['701627', '100CL Crème Cassis 15° MB', 'COL', '13.7460', '20', 'SPIRITUEUX'],
            ['067753', '75CL Prosecco Perlino', 'CAR', '7.8320', '20', 'VINS'],
            ['699974', '75CL Liby Zero Alcool Rosé Fruité', 'COL', '6.0180', '20', 'VINS'],
            ['011152', '100CL Ricard 45°', 'COL', '10.3550', '20', 'SPIRITUEUX'],
            ['000602', '70CL Vodka Sobieski 37,5° MB', 'COL', '6.5120', '20', 'SPIRITUEUX'],
            ['000502', '100CL Crème de Pêche 15° MB', 'COL', '13.7460', '20', 'SPIRITUEUX'],
            ['007067', '30CL Boîte Coca Cola Slim x24', 'CAR', '0.7430', '5.5', 'SOFTS'],
        ];

        foreach ($catalogue as $item) {
            $this->createProduitFournisseurAndMercuriale(
                $f, $etab, $admin,
                $item[0], $item[1], $item[2], $item[3], $item[4], $item[5],
            );
        }

        return $f;
    }

    // ─── BL 1 — TerreAzur 26/08/2025 ───────────────────────────

    private function createBL1TerreAzur(Etablissement $etab, Fournisseur $f, Utilisateur $admin): void
    {
        $bl = $this->createBL($etab, $f, $admin, '7830896247', '3117866318', '2025-08-26');

        $lignes = [
            ['103634', 'Sal jp mel provençal ½ 500gX2 100% FR', '2.000', 'KG', '7.1100', '14.2200'],
            ['106535', 'Oignon rge 60/80 5K c1 FR', '5.000', 'KG', '3.2400', '16.2000'],
            ['149543', 'Oignon bl cébette botte 15P FR', '10.000', 'BOT', '1.6500', '17.2500'],
            ['160823', 'Tom côtelée jne 3,5K c2 FR', '3.500', 'KG', '4.5000', '15.7500'],
            ['188800', 'Framboise bq 125gX12 c1 REG', '2.000', 'BQT', '3.2500', '6.5600'],
            ['189974', 'Avocat hass 165/196g pad22F SelTA c1ZA', '22.000', 'PU', '0.9000', '19.8000'],
            ['244337', 'Aubergine 300/400 HVE plt 5K B&E c1 REG', '5.000', 'KG', '3.6000', '18.0000'],
            ['249586', 'Pom golden 170/200 CE2 1r 7K B&E c1 FR', '6.100', 'KG', '2.3500', '14.3400'],
            ['319348', 'Fleur pensée jne bq 25P IEG FR', '8.000', 'BQT', '7.1100', '56.8800'],
            ['319451', 'Fleur pensée bl et bleu bq 25P IEG FR', '2.000', 'BQT', '7.1100', '14.2200'],
            ['114412', 'Ft dorade roy el 130/180 AP gr SA 20P', '6.000', 'KG', '20.0450', '120.2700'],
        ];

        $this->addLignes($bl, $f, $lignes);
    }

    // ─── BL 2 — TerreAzur 05/09/2025 ───────────────────────────

    private function createBL2TerreAzur(Etablissement $etab, Fournisseur $f, Utilisateur $admin): void
    {
        $bl = $this->createBL($etab, $f, $admin, '7830900916', '3118203000', '2025-09-05');

        $lignes = [
            ['103634', 'Sal jp mel provençal ½ 500gX2 100% FR', '3.000', 'KG', '7.1100', '21.3300'],
            ['104884', 'Pdt agata gren ct 12,5K c1 FR', '12.500', 'KG', '2.6100', '32.6300'],
            // Échalion: prix livré 3.30 au lieu de 3.00 → écart +10% → ALERTE
            ['106139', 'Échalion 30/50 5K c1 FR', '5.000', 'KG', '3.3000', '16.5000'],
            ['107990', 'Poivron rouge 90/110 5K c1 MA', '5.000', 'KG', '2.8800', '14.4000'],
            ['110061', 'HF menthe sac 100g MA', '4.000', 'SAC', '1.9900', '7.9600'],
            ['112987', 'Fleur pensée 1bq FR', '8.000', 'BQT', '7.1100', '56.8800'],
            ['120715', 'Groseille rouge bq 125gX12 c1 FR', '2.000', 'BQT', '3.8700', '7.8000'],
            ['151265', 'Citron lime 4,5K c1 BR', '4.150', 'KG', '3.1000', '12.8700'],
            ['160823', 'Tom côtelée jne 3,5K c2 FR', '3.500', 'KG', '4.5000', '15.7500'],
            ['188800', 'Framboise bq 125gX12 c1 REG', '2.000', 'BQT', '3.2500', '6.5600'],
            ['189984', 'Avocat hass 165/196g pad22F SelTA c1PE', '22.000', 'PU', '0.9000', '19.8000'],
            ['250113', 'Mûre bq 125gX8 Driscoll c1 PT', '2.000', 'BQT', '3.8700', '7.8000'],
            ['252870', 'Pdt agata 50+ CE2 10K c1 REG', '60.000', 'KG', '0.9500', '57.0000'],
            // Courgette: prix livré 2.10 au lieu de 1.95 → écart +7.7% → ALERTE
            ['273349', 'Courgette 14/21 HVE 5K c1 FR', '5.000', 'KG', '2.1000', '10.5000'],
            ['321600', 'Myrtille bq 125gX12 Driscoll c1 PE', '2.000', 'BQT', '2.8800', '5.8200'],
        ];

        $this->addLignes($bl, $f, $lignes);
    }

    // ─── BL 3 — TerreAzur 11/08/2025 ───────────────────────────

    private function createBL3TerreAzur(Etablissement $etab, Fournisseur $f, Utilisateur $admin): void
    {
        $bl = $this->createBL($etab, $f, $admin, '7830900029', null, '2025-08-11');

        $lignes = [
            ['103634', 'Sal jp mel provençal ½ 500gX2 100% FR', '3.000', 'KG', '7.1100', '21.3300'],
            ['106529', 'Oignon rge 60/80 5K c1 ES', '5.000', 'KG', '3.1500', '15.7500'],
            ['107853', 'Concombre 400/500 12P c1 FR', '5.000', 'KG', '2.7900', '13.9500'],
            ['108046', 'Poivron rouge 80/100 5K c1 NL', '5.000', 'KG', '2.7900', '5.9500'],
            ['110270', 'HF coriandre sac 100g FR', '1.000', 'SAC', '1.9900', '1.9900'],
            ['142569', 'Sal cœur romaine flow 2pk1 c1 ES', '2.000', 'SAC', '2.5000', '5.0000'],
            ['151265', 'Citron lime 4,5K c1 BR', '4.100', 'KG', '3.1000', '12.7100'],
            ['209681', 'Fleur viola 1bq FR', '6.000', 'BQT', '7.1100', '42.6600'],
            ['249586', 'Pom golden 170/200 CE2 1r 7K B&E c1 FR', '6.925', 'KG', '2.3500', '16.2700'],
        ];

        $this->addLignes($bl, $f, $lignes);
    }

    // ─── BL 4 — Le Bihan TMEG 06/08/2025 ───────────────────────

    private function createBL4LeBihan(Etablissement $etab, Fournisseur $f, Utilisateur $admin): void
    {
        $bl = $this->createBL($etab, $f, $admin, '00210729', null, '2025-08-06');

        $lignes = [
            ['012992', 'FUT 20L Eguzki Blanche', '4.000', 'FUT', '4.1160', '343.8600'],
            ['016724', 'FUT 30L Tigre Bock 5°', '4.000', 'FUT', '2.9190', '398.8800'],
            ['001306', 'FUT 20L Grimbergen Blonde', '4.000', 'FUT', '4.6040', '411.7400'],
            ['001144', 'FUT 30L Limonade B Bordet', '1.000', 'CAR', '1.1210', '30.1100'],
            ['103820', '25CL Bud Tow Pac Insep VP', '1.000', 'CAR', '1.0830', '27.2300'],
            ['002001', '25CL La French Ginger Beer VP', '4.000', 'CAR', '1.1340', '178.0000'],
            ['003042', '33CL Minibocal Granini Nectar Abricot VP', '2.000', 'CAR', '0.8110', '20.7200'],
            ['002335', '33CL Boîte Coca Cola Slim x24', '2.000', 'CAR', '0.7430', '41.2000'],
            ['002355', '33CL Boîte Coca Sans Sucre Slim x24', '2.000', 'CAR', '0.7730', '37.6500'],
            ['009975', '33CL Boîte Fuzetea Pêche Intense Slim', '2.000', 'CAR', '0.7680', '37.4900'],
            ['007129', '100CL Pet Abatilles Bord. Pétillante x12', '1.000', 'CAR', '1.0350', '12.4200'],
            ['000019', '70CL Baileys 17° Irish Cream', '5.000', 'COL', '14.7230', '84.9200'],
            ['707702', '70CL Limoncello M Brizard 25°', '1.000', 'COL', '9.3770', '2.2600'],
            ['005111', 'Tube CO2 Orange 10KGS', '2.000', 'UNI', '51.3360', '102.6700'],
        ];

        $this->addLignes($bl, $f, $lignes);
    }

    // ─── BL 5 — Le Bihan TMEG 12/08/2025 ───────────────────────

    private function createBL5LeBihan(Etablissement $etab, Fournisseur $f, Utilisateur $admin): void
    {
        $bl = $this->createBL($etab, $f, $admin, '00211162', null, '2025-08-12');

        $lignes = [
            ['012992', 'FUT 20L Eguzki Blanche', '1.000', 'FUT', '4.1160', '85.9700'],
            ['016724', 'FUT 30L Tigre Bock 5°', '1.000', 'FUT', '2.9190', '99.7200'],
            ['001144', 'FUT 30L Limonade B Bordet', '1.000', 'FUT', '1.1340', '44.5200'],
            ['002335', '33CL Boîte Coca Cola Slim x24', '4.000', 'CAR', '0.7430', '43.4900'],
            ['007127', '100CL Pet Abatilles Bord. Eau Plate x12', '5.000', 'CAR', '0.9060', '62.1000'],
            ['007129', '100CL Pet Abatilles Bord. Pétillante x12', '5.000', 'COL', '1.0350', '91.7000'],
            ['011152', '100CL Ricard 45°', '1.000', 'COL', '10.3550', '8.5500'],
            ['000602', '70CL Vodka Sobieski 37,5° MB', '5.000', 'COL', '6.5120', '57.4800'],
            ['001131', '70CL Cognac VS 40° Hennessy', '2.000', 'COL', '25.6420', '61.9200'],
            ['701625', '70CL Liqueur Mint\'s 15° MB', '5.000', 'COL', '7.6140', '48.0400'],
            ['706436', '70CL Liqueur Passion M Brizard 18°', '1.000', 'COL', '8.9950', '1.9900'],
            ['707702', '70CL Limoncello M Brizard 25°', '1.000', 'COL', '8.9950', '11.3800'],
            ['701627', '100CL Crème Cassis 15° MB', '1.000', 'COL', '13.7460', '16.6000'],
            // Prosecco: prix livré 8.50 au lieu de 7.832 → écart +8.5% → ALERTE
            ['067753', '75CL Prosecco Perlino', '5.000', 'CAR', '8.5000', '234.9600'],
            ['699974', '75CL Liby Zero Alcool Rosé Fruité', '5.000', 'COL', '6.0180', '180.5400'],
        ];

        $this->addLignes($bl, $f, $lignes);
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * @param array{nom: string, code: string, siret: string, adresse: string, codePostal: string, ville: string, telephone?: string} $data
     */
    private function findOrCreateFournisseur(array $data): Fournisseur
    {
        $f = $this->manager->getRepository(Fournisseur::class)->findOneBy(['siret' => $data['siret']]);
        if ($f) {
            return $f;
        }

        $f = new Fournisseur();
        $f->setNom($data['nom']);
        $f->setCode($data['code']);
        $f->setSiret($data['siret']);
        $f->setAdresse($data['adresse']);
        $f->setCodePostal($data['codePostal']);
        $f->setVille($data['ville']);
        if (isset($data['telephone'])) {
            $f->setTelephone($data['telephone']);
        }
        $f->setActif(true);
        $this->manager->persist($f);

        return $f;
    }

    private function ensureOrganisationFournisseur(Organisation $org, Fournisseur $f, string $codeClient): void
    {
        $existing = $this->manager->getRepository(OrganisationFournisseur::class)->findOneBy([
            'organisation' => $org,
            'fournisseur' => $f,
        ]);
        if ($existing) {
            return;
        }

        $of = new OrganisationFournisseur();
        $of->setOrganisation($org);
        $of->setFournisseur($f);
        $of->setCodeClient($codeClient);
        $of->setActif(true);
        $this->manager->persist($of);
    }

    private function createProduitFournisseurAndMercuriale(
        Fournisseur $f,
        Etablissement $etab,
        Utilisateur $admin,
        string $code,
        string $designation,
        string $uniteCode,
        string $prix,
        string $tva,
        string $categorieCode,
    ): void {
        $unite = $this->unites[$uniteCode];
        $categorie = $this->categories[$categorieCode] ?? null;

        $produit = new Produit();
        $produit->setNom($designation);
        $produit->setCodeInterne($code);
        $produit->setUniteBase($unite);
        $produit->setCategorie($categorie);
        $produit->setActif(true);
        $this->manager->persist($produit);

        $pf = new ProduitFournisseur();
        $pf->setFournisseur($f);
        $pf->setProduit($produit);
        $pf->setCodeFournisseur($code);
        $pf->setDesignationFournisseur($designation);
        $pf->setUniteAchat($unite);
        $pf->setActif(true);
        $this->manager->persist($pf);

        $key = $f->getSiret() . '_' . $code;
        $this->produitsFournisseur[$key] = $pf;

        $merc = new Mercuriale();
        $merc->setProduitFournisseur($pf);
        $merc->setEtablissement($etab);
        $merc->setPrixNegocie($prix);
        $merc->setDateDebut(new \DateTimeImmutable('2025-07-01'));
        $merc->setSeuilAlertePct('5.00');
        $merc->setCreatedBy($admin);
        $this->manager->persist($merc);
    }

    private function createBL(
        Etablissement $etab,
        Fournisseur $f,
        Utilisateur $admin,
        string $numeroBl,
        ?string $numeroCommande,
        string $dateLivraison,
    ): BonLivraison {
        $bl = new BonLivraison();
        $bl->setEtablissement($etab);
        $bl->setFournisseur($f);
        $bl->setNumeroBl($numeroBl);
        $bl->setNumeroCommande($numeroCommande);
        $bl->setDateLivraison(new \DateTimeImmutable($dateLivraison));
        $bl->setStatut(StatutBonLivraison::BROUILLON);
        $bl->setCreatedBy($admin);
        $this->manager->persist($bl);

        return $bl;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $lignesData
     */
    private function addLignes(BonLivraison $bl, Fournisseur $f, array $lignesData): void
    {
        $ordre = 1;
        foreach ($lignesData as $data) {
            [$code, $designation, $qte, $uniteCode, $pu, $total] = $data;

            $unite = $this->unites[$uniteCode];
            $key = $f->getSiret() . '_' . $code;
            $pf = $this->produitsFournisseur[$key] ?? null;

            $ligne = new LigneBonLivraison();
            $ligne->setBonLivraison($bl);
            $ligne->setCodeProduitBl($code);
            $ligne->setDesignationBl($designation);
            $ligne->setQuantiteLivree($qte);
            $ligne->setUnite($unite);
            $ligne->setPrixUnitaire($pu);
            $ligne->setTotalLigne($total);
            $ligne->setOrdre($ordre++);
            $ligne->setProduitFournisseur($pf);

            if ($pf) {
                $this->checkPriceAndCreateAlert($ligne, $pf, $pu);
            }

            $bl->addLigne($ligne);
            $this->manager->persist($ligne);
        }

        $bl->setTotalHt($bl->calculerTotalHt());
    }

    private function checkPriceAndCreateAlert(LigneBonLivraison $ligne, ProduitFournisseur $pf, string $puLivre): void
    {
        $mercuriales = $this->manager->getRepository(Mercuriale::class)->findBy([
            'produitFournisseur' => $pf,
        ]);

        if (empty($mercuriales)) {
            return;
        }

        $merc = $mercuriales[0];
        $prixRef = (float) $merc->getPrixNegocie();
        $prixBl = (float) $puLivre;

        if ($prixRef <= 0) {
            return;
        }

        $ecart = (($prixBl - $prixRef) / $prixRef) * 100;
        $seuil = $merc->getSeuilAlertePctAsFloat();

        if (abs($ecart) > $seuil) {
            $alerte = new AlerteControle();
            $alerte->setLigneBl($ligne);
            $alerte->setTypeAlerte(TypeAlerte::ECART_PRIX);
            $alerte->setMessage(sprintf(
                'Écart prix %+.1f%% : PU BL %.4f € vs mercuriale %.4f €',
                $ecart,
                $prixBl,
                $prixRef,
            ));
            $alerte->setValeurAttendue($merc->getPrixNegocie());
            $alerte->setValeurRecue($puLivre);
            $alerte->setEcartPct(number_format($ecart, 2, '.', ''));
            $ligne->addAlerte($alerte);
            $ligne->setStatutControle(StatutControle::ECART_PRIX);
            $this->manager->persist($alerte);
        } else {
            $ligne->setStatutControle(StatutControle::OK);
        }
    }
}
