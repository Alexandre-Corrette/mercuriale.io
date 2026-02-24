<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BonLivraisonUploadControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAccessUploadPageWithoutAuthentication(): void
    {
        $this->client->request('GET', '/app/bl/upload');

        $this->assertResponseRedirects('/login');
    }

    public function testAccessUploadPageWithAuthentication(): void
    {
        $user = $this->createTestUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/app/bl/upload');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form#upload-form');
    }

    public function testUploadWithUnauthorizedEtablissement(): void
    {
        // Créer un utilisateur sans accès à aucun établissement
        $user = $this->createTestUser();

        // Créer un établissement auquel l'utilisateur n'a PAS accès
        $organisation = new Organisation();
        $organisation->setNom('Autre Organisation');
        $this->entityManager->persist($organisation);

        $etablissement = new Etablissement();
        $etablissement->setNom('Etablissement Interdit');
        $etablissement->setOrganisation($organisation);
        $etablissement->setActif(true);
        $this->entityManager->persist($etablissement);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        // Accéder au formulaire d'upload
        $crawler = $this->client->request('GET', '/app/bl/upload');
        $this->assertResponseIsSuccessful();

        // Le formulaire filtre les établissements par accès utilisateur,
        // donc l'établissement interdit ne devrait pas apparaître dans le select
        $options = $crawler->filter('#bon_livraison_upload_etablissement option')->each(
            fn ($node) => $node->attr('value')
        );
        $this->assertNotContains((string) $etablissement->getId(), $options);
    }

    public function testUploadValidFile(): void
    {
        // Créer un utilisateur avec accès à un établissement
        $user = $this->createTestUserWithAccess();
        $this->client->loginUser($user);

        // Récupérer l'établissement accessible
        $etablissement = $this->entityManager->getRepository(Etablissement::class)
            ->findOneBy(['actif' => true]);

        if ($etablissement === null) {
            $this->markTestSkipped('Aucun établissement disponible pour le test');
        }

        $testFile = $this->createTestJpegFile();

        $crawler = $this->client->request('GET', '/app/bl/upload');

        // Extraire le token CSRF
        $form = $crawler->filter('form#upload-form')->form();
        $csrfToken = $form->get('bon_livraison_upload[_token]')->getValue();

        $uploadedFile = new UploadedFile($testFile, 'test.jpg', 'image/jpeg', null, true);

        $this->client->request(
            'POST',
            '/app/bl/upload',
            ['bon_livraison_upload' => [
                'etablissement' => $etablissement->getId(),
                '_token' => $csrfToken,
            ]],
            ['bon_livraison_upload' => ['files' => [$uploadedFile]]],
        );

        // Devrait rediriger vers la page d'extraction
        $this->assertResponseRedirects();

        // Nettoyer
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    public function testUploadWithInvalidCsrfToken(): void
    {
        $user = $this->createTestUserWithAccess();
        $this->client->loginUser($user);

        $etablissement = $this->entityManager->getRepository(Etablissement::class)
            ->findOneBy(['actif' => true]);

        if ($etablissement === null) {
            $this->markTestSkipped('Aucun établissement disponible pour le test');
        }

        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile($testFile, 'test.jpg', 'image/jpeg', null, true);

        $this->client->request(
            'POST',
            '/app/bl/upload',
            ['bon_livraison_upload' => [
                'etablissement' => $etablissement->getId(),
                '_token' => 'invalid_csrf_token',
            ]],
            ['bon_livraison_upload' => ['files' => [$uploadedFile]]],
        );

        // Le formulaire devrait être invalide (CSRF) — re-affiche avec erreur (422)
        $this->assertResponseStatusCodeSame(422);

        // Nettoyer
        if (file_exists($testFile)) {
            unlink($testFile);
        }
    }

    private function createTestUser(): Utilisateur
    {
        $organisation = $this->entityManager->getRepository(Organisation::class)
            ->findOneBy([]) ?? $this->createOrganisation();

        $user = new Utilisateur();
        $user->setEmail('test_' . uniqid() . '@example.com');
        $user->setNom('Test');
        $user->setPrenom('User');
        $user->setOrganisation($organisation);
        $user->setRoles(['ROLE_USER']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTestUserWithAccess(): Utilisateur
    {
        $organisation = $this->entityManager->getRepository(Organisation::class)
            ->findOneBy([]) ?? $this->createOrganisation();

        $user = new Utilisateur();
        $user->setEmail('test_access_' . uniqid() . '@example.com');
        $user->setNom('Test');
        $user->setPrenom('Admin');
        $user->setOrganisation($organisation);
        $user->setRoles(['ROLE_ADMIN']); // ADMIN a accès à tous les établissements de son org

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createOrganisation(): Organisation
    {
        $organisation = new Organisation();
        $organisation->setNom('Test Organisation ' . uniqid());

        $this->entityManager->persist($organisation);

        // Créer un établissement pour cette organisation
        $etablissement = new Etablissement();
        $etablissement->setNom('Test Etablissement');
        $etablissement->setOrganisation($organisation);
        $etablissement->setActif(true);

        $this->entityManager->persist($etablissement);
        $this->entityManager->flush();

        return $organisation;
    }

    private function createTestJpegFile(): string
    {
        $testFile = sys_get_temp_dir() . '/test_upload_' . uniqid() . '.jpg';

        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor(100, 100);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
            imagejpeg($image, $testFile, 90);
            imagedestroy($image);
        } else {
            // Minimal valid JPEG
            $minimalJpeg = base64_decode(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof' .
                'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh' .
                'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAAR' .
                'CAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAA' .
                'AAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMB' .
                'AAIRAxEAPwCwAB//2Q=='
            );
            file_put_contents($testFile, $minimalJpeg);
        }

        return $testFile;
    }
}
