<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\CategorieProduit;
use App\Entity\ConversionUnite;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\OrganisationFournisseur;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
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
        // 1. Organisation
        $organisation = new Organisation();
        $organisation->setNom('Groupe Horao');
        $organisation->setSiret('12345678901234');
        $manager->persist($organisation);

        // 2. Unités
        $unites = $this->createUnites($manager);

        // 3. Conversions d'unités
        $this->createConversions($manager, $unites);

        // 4. Catégories de produits
        $this->createCategories($manager);

        // 5. Établissements
        $etablissements = $this->createEtablissements($manager, $organisation);

        // 6. Fournisseurs (indépendants)
        $fournisseurs = $this->createFournisseurs($manager);

        // 7. Associations Organisation-Fournisseur
        $this->createOrganisationFournisseurs($manager, $organisation, $fournisseurs);

        // 8. Utilisateur admin
        $admin = $this->createAdmin($manager, $organisation, $etablissements);

        $manager->flush();
    }

    /**
     * @return array<string, Unite>
     */
    private function createUnites(ObjectManager $manager): array
    {
        $unitesData = [
            // Poids
            ['nom' => 'Kilogramme', 'code' => 'kg', 'type' => TypeUnite::POIDS, 'ordre' => 1],
            ['nom' => 'Gramme', 'code' => 'g', 'type' => TypeUnite::POIDS, 'ordre' => 2],
            // Volume
            ['nom' => 'Litre', 'code' => 'L', 'type' => TypeUnite::VOLUME, 'ordre' => 3],
            ['nom' => 'Centilitre', 'code' => 'cL', 'type' => TypeUnite::VOLUME, 'ordre' => 4],
            ['nom' => 'Millilitre', 'code' => 'mL', 'type' => TypeUnite::VOLUME, 'ordre' => 5],
            // Quantité
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
            // kg <-> g
            ['source' => 'kg', 'cible' => 'g', 'facteur' => '1000.000000'],
            ['source' => 'g', 'cible' => 'kg', 'facteur' => '0.001000'],
            // L <-> cL
            ['source' => 'L', 'cible' => 'cL', 'facteur' => '100.000000'],
            ['source' => 'cL', 'cible' => 'L', 'facteur' => '0.010000'],
            // L <-> mL
            ['source' => 'L', 'cible' => 'mL', 'facteur' => '1000.000000'],
            ['source' => 'mL', 'cible' => 'L', 'facteur' => '0.001000'],
            // cL <-> mL
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
     * @return Etablissement[]
     */
    private function createEtablissements(ObjectManager $manager, Organisation $organisation): array
    {
        $etablissementsData = [
            [
                'nom' => 'Escale Parmentier',
                'adresse' => '62 rue Jean-Pierre Timbaud',
                'codePostal' => '75011',
                'ville' => 'Paris',
            ],
            [
                'nom' => 'Escale sur la Plage',
                'adresse' => null,
                'codePostal' => null,
                'ville' => null,
            ],
            [
                'nom' => 'Guinguette du Château',
                'adresse' => null,
                'codePostal' => null,
                'ville' => null,
            ],
            [
                'nom' => 'Le Prieuré',
                'adresse' => null,
                'codePostal' => null,
                'ville' => null,
            ],
        ];

        $etablissements = [];
        foreach ($etablissementsData as $data) {
            $etablissement = new Etablissement();
            $etablissement->setOrganisation($organisation);
            $etablissement->setNom($data['nom']);
            $etablissement->setAdresse($data['adresse']);
            $etablissement->setCodePostal($data['codePostal']);
            $etablissement->setVille($data['ville']);
            $etablissement->setActif(true);
            $manager->persist($etablissement);
            $etablissements[] = $etablissement;
        }

        return $etablissements;
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

            // Add some sample data for the first fournisseur
            if ($fournisseur->getCode() === 'FOODFLOW') {
                $orgFournisseur->setCodeClient('CLI-HORAO-001');
                $orgFournisseur->setContactCommercial('Jean Dupont');
                $orgFournisseur->setEmailCommande('commandes@foodflow.fr');
            }

            $manager->persist($orgFournisseur);
        }
    }

    /**
     * @param Etablissement[] $etablissements
     */
    private function createAdmin(ObjectManager $manager, Organisation $organisation, array $etablissements): Utilisateur
    {
        $admin = new Utilisateur();
        $admin->setOrganisation($organisation);
        $admin->setEmail('admin@mercuriale.io');
        $admin->setNom('Admin');
        $admin->setPrenom('Super');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $admin->setActif(true);
        $manager->persist($admin);

        // Associer l'admin à tous les établissements
        foreach ($etablissements as $etablissement) {
            $ue = new UtilisateurEtablissement();
            $ue->setUtilisateur($admin);
            $ue->setEtablissement($etablissement);
            $ue->setRole('ROLE_ADMIN');
            $manager->persist($ue);
        }

        return $admin;
    }
}
