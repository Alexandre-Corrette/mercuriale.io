<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Abonnement;
use App\Entity\CategorieProduit;
use App\Entity\ConversionUnite;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\OrganisationFournisseur;
use App\Entity\PlanType;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use App\Entity\UtilisateurOrganisation;
use App\Enum\TypeEtablissement;
use App\Enum\TypeUnite;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // 1. Unités
        $unites = $this->createUnites($manager);

        // 2. Conversions d'unités
        $this->createConversions($manager, $unites);

        // 3. Catégories de produits
        $this->createCategories($manager);

        // ──────────────────────────────────────────────────────────────
        // Organisation 1 — Escale sur la Plage (multi-établissement, plan MULTI)
        // ──────────────────────────────────────────────────────────────
        $escale = new Organisation();
        $escale->setNom('Escale sur la Plage');
        $escale->setSiren('123456789');
        $escale->setSiret('12345678901234');
        $escale->setVerifiedAt(new \DateTimeImmutable('2025-06-01'));
        $escale->setTrialEndsAt(new \DateTimeImmutable('2025-07-01'));
        $manager->persist($escale);

        $aboEscale = new Abonnement();
        $aboEscale->setOrganisation($escale);
        $aboEscale->setPlan(PlanType::MULTI);
        $aboEscale->setStartsAt(new \DateTimeImmutable('2025-07-01'));
        $aboEscale->setStripeSubscriptionId('sub_escale_multi_fake');
        $aboEscale->setActive(true);
        $manager->persist($aboEscale);

        $escaleParmentier = $this->createEtablissement($manager, $escale, [
            'nom' => 'Escale Parmentier',
            'siret' => '12345678901234',
            'adresse' => '62 rue Jean-Pierre Timbaud',
            'codePostal' => '75011',
            'ville' => 'Paris',
            'type' => TypeEtablissement::RESTAURANT,
            'codeNaf' => '56.10A',
            'isPrimary' => true,
        ]);

        $guinguette = $this->createEtablissement($manager, $escale, [
            'nom' => 'Guinguette du Château',
            'siret' => '12345678902345',
            'adresse' => 'Lieu dit Laubrade',
            'codePostal' => '33230',
            'ville' => 'Abzac',
            'type' => TypeEtablissement::RESTAURANT,
            'codeNaf' => '56.10A',
            'isPrimary' => false,
        ]);

        $prieure = $this->createEtablissement($manager, $escale, [
            'nom' => 'Le Prieuré',
            'siret' => '12345678903456',
            'adresse' => '1 place du Prieuré',
            'codePostal' => '33230',
            'ville' => 'Abzac',
            'type' => TypeEtablissement::RESTAURANT,
            'codeNaf' => '56.10A',
            'isPrimary' => false,
        ]);

        $escaleEtabs = [$escaleParmentier, $guinguette, $prieure];

        // Admin Escale — accès à tous les établissements
        $adminEscale = $this->createUser($manager, $escale, [
            'email' => 'admin@escale.fr',
            'nom' => 'Corrette',
            'prenom' => 'Alexandre',
            'roles' => ['ROLE_ADMIN'],
            'password' => 'admin123',
        ]);
        $this->linkUserToOrganisation($manager, $adminEscale, $escale, 'owner');
        foreach ($escaleEtabs as $etab) {
            $this->linkUserToEtablissement($manager, $adminEscale, $etab, 'ROLE_ADMIN');
        }

        // Gérant Escale — accès uniquement à Escale Parmentier
        $gerant = $this->createUser($manager, $escale, [
            'email' => 'gerant@escale.fr',
            'nom' => 'Martin',
            'prenom' => 'Sophie',
            'roles' => ['ROLE_GERANT'],
            'password' => 'gerant123',
        ]);
        $this->linkUserToOrganisation($manager, $gerant, $escale, 'member');
        $this->linkUserToEtablissement($manager, $gerant, $escaleParmentier, 'ROLE_GERANT');

        // Cuisinier Escale — consultation uniquement
        $cuisinier = $this->createUser($manager, $escale, [
            'email' => 'cuisinier@escale.fr',
            'nom' => 'Dupont',
            'prenom' => 'Pierre',
            'roles' => ['ROLE_CUISINIER'],
            'password' => 'cuisinier123',
        ]);
        $this->linkUserToOrganisation($manager, $cuisinier, $escale, 'member');
        $this->linkUserToEtablissement($manager, $cuisinier, $escaleParmentier, 'ROLE_CUISINIER');

        // Fournisseurs Escale
        $fournisseurs = $this->createFournisseurs($manager);
        $this->createOrganisationFournisseurs($manager, $escale, $fournisseurs);

        // Associer les fournisseurs aux établissements Escale
        foreach ($fournisseurs as $fournisseur) {
            foreach ($escaleEtabs as $etab) {
                $fournisseur->addEtablissement($etab);
            }
        }

        // ──────────────────────────────────────────────────────────────
        // Organisation 2 — Le Zinc d'Arthur (bar, plan SINGLE)
        // ──────────────────────────────────────────────────────────────
        $zincOrg = new Organisation();
        $zincOrg->setNom('Le Zinc d\'Arthur SARL');
        $zincOrg->setSiren('987654321');
        $zincOrg->setSiret('98765432100012');
        $zincOrg->setVerifiedAt(new \DateTimeImmutable('2025-09-15'));
        $zincOrg->setTrialEndsAt(new \DateTimeImmutable('2025-10-15'));
        $manager->persist($zincOrg);

        $aboZinc = new Abonnement();
        $aboZinc->setOrganisation($zincOrg);
        $aboZinc->setPlan(PlanType::SINGLE);
        $aboZinc->setStartsAt(new \DateTimeImmutable('2025-10-15'));
        $aboZinc->setStripeSubscriptionId('sub_zinc_single_fake');
        $aboZinc->setActive(true);
        $manager->persist($aboZinc);

        $zincEtab = $this->createEtablissement($manager, $zincOrg, [
            'nom' => 'Le Zinc d\'Arthur',
            'siret' => '98765432100012',
            'adresse' => '15 rue de la Soif',
            'codePostal' => '33000',
            'ville' => 'Bordeaux',
            'type' => TypeEtablissement::BAR,
            'codeNaf' => '56.30Z',
            'isPrimary' => true,
        ]);

        $arthur = $this->createUser($manager, $zincOrg, [
            'email' => 'arthur@lezinc.fr',
            'nom' => 'Renaud',
            'prenom' => 'Arthur',
            'roles' => ['ROLE_ADMIN', 'ROLE_GERANT'],
            'password' => 'arthur123',
        ]);
        $this->linkUserToOrganisation($manager, $arthur, $zincOrg, 'owner');
        $this->linkUserToEtablissement($manager, $arthur, $zincEtab, 'ROLE_ADMIN');

        // Fournisseur boissons pour le bar
        $zincFournisseur = $this->findFournisseurByCode($fournisseurs, 'METRO');
        if ($zincFournisseur) {
            $ofZinc = new OrganisationFournisseur();
            $ofZinc->setOrganisation($zincOrg);
            $ofZinc->setFournisseur($zincFournisseur);
            $ofZinc->setCodeClient('CLI-ZINC-001');
            $ofZinc->setActif(true);
            $manager->persist($ofZinc);
            $zincFournisseur->addEtablissement($zincEtab);
        }

        // ──────────────────────────────────────────────────────────────
        // Organisation 3 — Chez Marie (trial, fraîchement inscrit)
        // ──────────────────────────────────────────────────────────────
        $marieOrg = new Organisation();
        $marieOrg->setNom('Chez Marie');
        $marieOrg->setSiren('456789123');
        $marieOrg->setSiret('45678912300018');
        $marieOrg->setTrialEndsAt(new \DateTimeImmutable('+14 days'));
        $manager->persist($marieOrg);

        $aboMarie = new Abonnement();
        $aboMarie->setOrganisation($marieOrg);
        $aboMarie->setPlan(PlanType::TRIAL);
        $aboMarie->setStartsAt(new \DateTimeImmutable());
        $aboMarie->setActive(true);
        $manager->persist($aboMarie);

        $marieEtab = $this->createEtablissement($manager, $marieOrg, [
            'nom' => 'Chez Marie',
            'siret' => '45678912300018',
            'adresse' => '8 place du Marché',
            'codePostal' => '33500',
            'ville' => 'Libourne',
            'type' => TypeEtablissement::RESTAURANT,
            'codeNaf' => '56.10A',
            'isPrimary' => true,
        ]);

        $marie = $this->createUser($manager, $marieOrg, [
            'email' => 'marie@chezmarie.fr',
            'nom' => 'Lefebvre',
            'prenom' => 'Marie',
            'roles' => ['ROLE_ADMIN'],
            'password' => 'marie123',
        ]);
        $this->linkUserToOrganisation($manager, $marie, $marieOrg, 'owner');
        $this->linkUserToEtablissement($manager, $marie, $marieEtab, 'ROLE_ADMIN');

        // Store references for dependent fixtures
        $this->addReference('org-escale', $escale);
        $this->addReference('org-zinc', $zincOrg);
        $this->addReference('org-marie', $marieOrg);
        $this->addReference('etab-escale', $escaleParmentier);
        $this->addReference('etab-guinguette', $guinguette);
        $this->addReference('etab-prieure', $prieure);
        $this->addReference('etab-zinc', $zincEtab);
        $this->addReference('etab-marie', $marieEtab);
        $this->addReference('user-admin-escale', $adminEscale);
        $this->addReference('user-arthur', $arthur);
        $this->addReference('user-marie', $marie);

        $manager->flush();
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * @param array{nom: string, siret?: string, adresse?: string|null, codePostal?: string|null, ville?: string|null, type?: TypeEtablissement, codeNaf?: string, isPrimary?: bool} $data
     */
    private function createEtablissement(ObjectManager $manager, Organisation $org, array $data): Etablissement
    {
        $etab = new Etablissement();
        $etab->setOrganisation($org);
        $etab->setNom($data['nom']);
        $etab->setSiret($data['siret'] ?? null);
        $etab->setAdresse($data['adresse'] ?? null);
        $etab->setCodePostal($data['codePostal'] ?? null);
        $etab->setVille($data['ville'] ?? null);
        $etab->setTypeEtablissement($data['type'] ?? null);
        $etab->setCodeNaf($data['codeNaf'] ?? null);
        $etab->setIsPrimary($data['isPrimary'] ?? false);
        $etab->setActif(true);
        $manager->persist($etab);

        return $etab;
    }

    /**
     * @param array{email: string, nom: string, prenom: string, roles: string[], password: string} $data
     */
    private function createUser(ObjectManager $manager, Organisation $org, array $data): Utilisateur
    {
        $user = new Utilisateur();
        $user->setOrganisation($org);
        $user->setEmail($data['email']);
        $user->setNom($data['nom']);
        $user->setPrenom($data['prenom']);
        $user->setRoles($data['roles']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $data['password']));
        $user->setActif(true);
        $manager->persist($user);

        return $user;
    }

    private function linkUserToOrganisation(ObjectManager $manager, Utilisateur $user, Organisation $org, string $role): void
    {
        $uo = new UtilisateurOrganisation();
        $uo->setUtilisateur($user);
        $uo->setOrganisation($org);
        $uo->setRole($role);
        $manager->persist($uo);
    }

    private function linkUserToEtablissement(ObjectManager $manager, Utilisateur $user, Etablissement $etab, string $role): void
    {
        $ue = new UtilisateurEtablissement();
        $ue->setUtilisateur($user);
        $ue->setEtablissement($etab);
        $ue->setRole($role);
        $manager->persist($ue);
    }

    /**
     * @return array<string, Unite>
     */
    private function createUnites(ObjectManager $manager): array
    {
        $unitesData = [
            ['nom' => 'Kilogramme', 'code' => 'kg', 'type' => TypeUnite::POIDS, 'ordre' => 1],
            ['nom' => 'Gramme', 'code' => 'g', 'type' => TypeUnite::POIDS, 'ordre' => 2],
            ['nom' => 'Litre', 'code' => 'L', 'type' => TypeUnite::VOLUME, 'ordre' => 3],
            ['nom' => 'Centilitre', 'code' => 'cL', 'type' => TypeUnite::VOLUME, 'ordre' => 4],
            ['nom' => 'Millilitre', 'code' => 'mL', 'type' => TypeUnite::VOLUME, 'ordre' => 5],
            ['nom' => 'Pièce', 'code' => 'p', 'type' => TypeUnite::QUANTITE, 'ordre' => 6],
            ['nom' => 'Barquette', 'code' => 'bq', 'type' => TypeUnite::QUANTITE, 'ordre' => 7],
            ['nom' => 'Bouteille', 'code' => 'bt', 'type' => TypeUnite::QUANTITE, 'ordre' => 8],
            ['nom' => 'Carton', 'code' => 'ct', 'type' => TypeUnite::QUANTITE, 'ordre' => 9],
            ['nom' => 'Lot', 'code' => 'lot', 'type' => TypeUnite::QUANTITE, 'ordre' => 10],
        ];

        $unites = [];
        foreach ($unitesData as $data) {
            $unite = new Unite();
            $unite->setNom($data['nom']);
            $unite->setCode($data['code']);
            $unite->setType($data['type']);
            $unite->setOrdre($data['ordre']);
            $manager->persist($unite);
            $unites[$data['code']] = $unite;
        }

        return $unites;
    }

    /**
     * @param array<string, Unite> $unites
     */
    private function createConversions(ObjectManager $manager, array $unites): void
    {
        $conversionsData = [
            ['source' => 'kg', 'cible' => 'g', 'facteur' => '1000.000000'],
            ['source' => 'g', 'cible' => 'kg', 'facteur' => '0.001000'],
            ['source' => 'L', 'cible' => 'cL', 'facteur' => '100.000000'],
            ['source' => 'cL', 'cible' => 'L', 'facteur' => '0.010000'],
            ['source' => 'L', 'cible' => 'mL', 'facteur' => '1000.000000'],
            ['source' => 'mL', 'cible' => 'L', 'facteur' => '0.001000'],
            ['source' => 'cL', 'cible' => 'mL', 'facteur' => '10.000000'],
            ['source' => 'mL', 'cible' => 'cL', 'facteur' => '0.100000'],
        ];

        foreach ($conversionsData as $data) {
            $conversion = new ConversionUnite();
            $conversion->setUniteSource($unites[$data['source']]);
            $conversion->setUniteCible($unites[$data['cible']]);
            $conversion->setFacteur($data['facteur']);
            $manager->persist($conversion);
        }
    }

    private function createCategories(ObjectManager $manager): void
    {
        $categoriesData = [
            ['nom' => 'Fruits', 'code' => 'FRUITS', 'ordre' => 1],
            ['nom' => 'Légumes', 'code' => 'LEGUMES', 'ordre' => 2],
            ['nom' => 'Crèmerie', 'code' => 'CREMERIE', 'ordre' => 3],
            ['nom' => 'Boucherie', 'code' => 'BOUCHERIE', 'ordre' => 4],
            ['nom' => 'Poissonnerie', 'code' => 'POISSONNERIE', 'ordre' => 5],
            ['nom' => 'Épicerie', 'code' => 'EPICERIE', 'ordre' => 6],
            ['nom' => 'Boissons', 'code' => 'BOISSONS', 'ordre' => 7],
            ['nom' => 'Surgelés', 'code' => 'SURGELES', 'ordre' => 8],
        ];

        foreach ($categoriesData as $data) {
            $categorie = new CategorieProduit();
            $categorie->setNom($data['nom']);
            $categorie->setCode($data['code']);
            $categorie->setOrdre($data['ordre']);
            $manager->persist($categorie);
        }
    }

    /**
     * @return Fournisseur[]
     */
    private function createFournisseurs(ObjectManager $manager): array
    {
        $fournisseursData = [
            [
                'nom' => 'FoodFlow',
                'code' => 'FOODFLOW',
                'adresse' => '40 rue des Martyrs',
                'codePostal' => '75009',
                'ville' => 'Paris',
                'email' => 'compta@foodflow.fr',
                'siret' => '92169611800014',
            ],
            [
                'nom' => 'Metro',
                'code' => 'METRO',
                'adresse' => '5 rue des Grands Prés',
                'codePostal' => '92000',
                'ville' => 'Nanterre',
                'email' => 'contact@metro.fr',
                'siret' => '30978893400015',
            ],
            [
                'nom' => 'Pomona',
                'code' => 'POMONA',
                'adresse' => '1 rue de la Logistique',
                'codePostal' => '94500',
                'ville' => 'Champigny-sur-Marne',
                'email' => 'service.client@pomona.fr',
                'siret' => '34343424300012',
            ],
        ];

        $fournisseurs = [];
        foreach ($fournisseursData as $data) {
            $fournisseur = new Fournisseur();
            $fournisseur->setNom($data['nom']);
            $fournisseur->setCode($data['code']);
            $fournisseur->setAdresse($data['adresse']);
            $fournisseur->setCodePostal($data['codePostal']);
            $fournisseur->setVille($data['ville']);
            $fournisseur->setEmail($data['email']);
            $fournisseur->setSiret($data['siret']);
            $fournisseur->setActif(true);
            $manager->persist($fournisseur);
            $fournisseurs[] = $fournisseur;
        }

        return $fournisseurs;
    }

    /**
     * @param Fournisseur[] $fournisseurs
     */
    private function createOrganisationFournisseurs(ObjectManager $manager, Organisation $organisation, array $fournisseurs): void
    {
        foreach ($fournisseurs as $fournisseur) {
            $orgFournisseur = new OrganisationFournisseur();
            $orgFournisseur->setOrganisation($organisation);
            $orgFournisseur->setFournisseur($fournisseur);
            $orgFournisseur->setActif(true);

            if ($fournisseur->getCode() === 'FOODFLOW') {
                $orgFournisseur->setCodeClient('CLI-ESCALE-001');
                $orgFournisseur->setContactCommercial('Jean Dupont');
                $orgFournisseur->setEmailCommande('commandes@foodflow.fr');
            }

            $manager->persist($orgFournisseur);
        }
    }

    /**
     * @param Fournisseur[] $fournisseurs
     */
    private function findFournisseurByCode(array $fournisseurs, string $code): ?Fournisseur
    {
        foreach ($fournisseurs as $f) {
            if ($f->getCode() === $code) {
                return $f;
            }
        }

        return null;
    }
}
