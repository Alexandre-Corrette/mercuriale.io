<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\OrganisationFournisseur;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MercurialeImportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAccessImportPageWithoutAuthentication(): void
    {
        $this->client->request('GET', '/app/mercuriale/import');

        $this->assertResponseRedirects('/login');
    }

    public function testAccessImportPageWithAuthentication(): void
    {
        $user = $this->createAdminUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/app/mercuriale/import');

        $this->assertResponseIsSuccessful();
    }

    /**
     * Regression test for MERC-40: fournisseur linked only via Etablissement
     * (no OrganisationFournisseur record) should NOT cause 403 on upload.
     */
    public function testUploadWithFournisseurLinkedViaEtablissement(): void
    {
        $organisation = $this->createOrganisation();
        $etablissement = $this->createEtablissement($organisation);
        $user = $this->createAdminUser($organisation);

        // Fournisseur linked ONLY via Etablissement, no OrganisationFournisseur
        $fournisseur = new Fournisseur();
        $fournisseur->setNom('Fournisseur Etab Only');
        $fournisseur->setActif(true);
        $fournisseur->addEtablissement($etablissement);
        $this->entityManager->persist($fournisseur);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/app/mercuriale/import');
        $this->assertResponseIsSuccessful();

        // The fournisseur should appear in the form dropdown
        $this->assertSelectorExists('select[name*="fournisseur"] option[value="' . $fournisseur->getId() . '"]');

        // Submit the form — should NOT return 403
        $testFile = $this->createTestCsvFile();
        $form = $crawler->selectButton('Importer')->form();

        $form['mercuriale_import_upload[fournisseur]'] = (string) $fournisseur->getId();

        $this->client->submit($form, [], [
            'mercuriale_import_upload' => [
                'file' => new UploadedFile($testFile, 'mercuriale.csv', 'text/csv', null, true),
            ],
        ]);

        // Should redirect to mapping step (success) or re-render form (validation), but NOT 403
        $this->assertResponseStatusCodeSame(302);

        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Fournisseur linked via OrganisationFournisseur should work (existing path).
     */
    public function testUploadWithFournisseurLinkedViaOrganisationFournisseur(): void
    {
        $organisation = $this->createOrganisation();
        $user = $this->createAdminUser($organisation);

        $fournisseur = new Fournisseur();
        $fournisseur->setNom('Fournisseur OrgF');
        $fournisseur->setActif(true);
        $this->entityManager->persist($fournisseur);

        $orgFournisseur = new OrganisationFournisseur();
        $orgFournisseur->setOrganisation($organisation);
        $orgFournisseur->setFournisseur($fournisseur);
        $orgFournisseur->setActif(true);
        $this->entityManager->persist($orgFournisseur);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/app/mercuriale/import');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('select[name*="fournisseur"] option[value="' . $fournisseur->getId() . '"]');

        $testFile = $this->createTestCsvFile();
        $form = $crawler->selectButton('Importer')->form();

        $form['mercuriale_import_upload[fournisseur]'] = (string) $fournisseur->getId();

        $this->client->submit($form, [], [
            'mercuriale_import_upload' => [
                'file' => new UploadedFile($testFile, 'mercuriale.csv', 'text/csv', null, true),
            ],
        ]);

        $this->assertResponseStatusCodeSame(302);

        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    /**
     * Fournisseur from another organisation should be denied.
     */
    public function testUploadWithForeignFournisseurReturns403(): void
    {
        $organisation = $this->createOrganisation();
        $otherOrganisation = $this->createOrganisation('Autre Organisation');
        $user = $this->createAdminUser($organisation);

        // Fournisseur linked to ANOTHER organisation only
        $fournisseur = new Fournisseur();
        $fournisseur->setNom('Fournisseur Etranger');
        $fournisseur->setActif(true);
        $this->entityManager->persist($fournisseur);

        $orgFournisseur = new OrganisationFournisseur();
        $orgFournisseur->setOrganisation($otherOrganisation);
        $orgFournisseur->setFournisseur($fournisseur);
        $orgFournisseur->setActif(true);
        $this->entityManager->persist($orgFournisseur);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        // Attempt direct POST with the foreign fournisseur ID (bypassing form dropdown)
        $crawler = $this->client->request('GET', '/app/mercuriale/import');
        $testFile = $this->createTestCsvFile();

        $form = $crawler->selectButton('Importer')->form();

        // Force the fournisseur value (would not appear in dropdown normally)
        $form['mercuriale_import_upload[fournisseur]']->disableValidation();
        $form['mercuriale_import_upload[fournisseur]'] = (string) $fournisseur->getId();

        $this->client->submit($form, [], [
            'mercuriale_import_upload' => [
                'file' => new UploadedFile($testFile, 'mercuriale.csv', 'text/csv', null, true),
            ],
        ]);

        $this->assertResponseStatusCodeSame(403);

        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    private function createOrganisation(string $nom = 'Test Organisation'): Organisation
    {
        $organisation = new Organisation();
        $organisation->setNom($nom . ' ' . uniqid());
        $this->entityManager->persist($organisation);
        $this->entityManager->flush();

        return $organisation;
    }

    private function createEtablissement(Organisation $organisation): Etablissement
    {
        $etablissement = new Etablissement();
        $etablissement->setNom('Test Etablissement ' . uniqid());
        $etablissement->setOrganisation($organisation);
        $etablissement->setActif(true);
        $this->entityManager->persist($etablissement);
        $this->entityManager->flush();

        return $etablissement;
    }

    private function createAdminUser(?Organisation $organisation = null): Utilisateur
    {
        $organisation ??= $this->createOrganisation();

        $user = new Utilisateur();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setNom('Test');
        $user->setPrenom('Admin');
        $user->setOrganisation($organisation);
        $user->setRoles(['ROLE_ADMIN']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTestCsvFile(): string
    {
        $testFile = sys_get_temp_dir() . '/test_mercuriale_' . uniqid() . '.csv';

        $csv = "Code fournisseur;Désignation;Prix;Unité;Conditionnement\n";
        $csv .= "ART001;Tomates rondes;2.50;KG;Colis 5kg\n";
        $csv .= "ART002;Pommes de terre;1.20;KG;Sac 10kg\n";

        file_put_contents($testFile, $csv);

        return $testFile;
    }
}
