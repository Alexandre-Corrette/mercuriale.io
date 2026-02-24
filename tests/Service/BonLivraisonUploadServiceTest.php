<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Exception\InvalidFileException;
use App\Service\Upload\BonLivraisonUploadService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class BonLivraisonUploadServiceTest extends TestCase
{
    private BonLivraisonUploadService $service;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&LoggerInterface $logger;
    private string $testDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->testDir = sys_get_temp_dir() . '/mercuriale_test_' . uniqid();

        mkdir($this->testDir, 0755, true);

        $this->service = new BonLivraisonUploadService(
            $this->entityManager,
            $this->logger,
            $this->testDir
        );
    }

    protected function tearDown(): void
    {
        // Nettoyer les fichiers de test
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testUploadValidJpegFile(): void
    {
        // Créer un vrai fichier JPEG de test
        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.jpg',
            'image/jpeg',
            null,
            true // test mode
        );

        $etablissement = $this->createEtablissement();
        $user = $this->createUser();

        $this->entityManager->expects($this->once())
            ->method('persist');
        $this->entityManager->expects($this->once())
            ->method('flush');

        $bonLivraison = $this->service->upload($uploadedFile, $etablissement, $user);

        $this->assertNotNull($bonLivraison->getImagePath());
        $this->assertSame($etablissement, $bonLivraison->getEtablissement());
        $this->assertSame($user, $bonLivraison->getCreatedBy());
    }

    public function testRejectFileTooLarge(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('trop volumineux');

        // Créer un mock de fichier trop gros
        $testFile = $this->createTestJpegFile();

        $uploadedFile = $this->createMock(UploadedFile::class);
        $uploadedFile->method('isValid')->willReturn(true);
        $uploadedFile->method('getSize')->willReturn(25 * 1024 * 1024); // 25 Mo
        $uploadedFile->method('getPathname')->willReturn($testFile);
        $uploadedFile->method('getMimeType')->willReturn('image/jpeg');

        $this->service->validateFile($uploadedFile);
    }

    public function testRejectInvalidMimeType(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('type de fichier');

        // Créer un fichier texte
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'This is a text file');

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.txt',
            'text/plain',
            null,
            true
        );

        $this->service->validateFile($uploadedFile);
    }

    public function testRejectPhpFileDisguisedAsImage(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('suspect');

        // Créer un fichier PHP déguisé en JPEG
        $testFile = $this->createTestJpegFile();
        $content = file_get_contents($testFile);
        // Injecter du code PHP dans le fichier
        file_put_contents($testFile, $content . '<?php echo "hacked"; ?>');

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->service->validateFile($uploadedFile);
    }

    public function testGenerateSecureFilename(): void
    {
        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile(
            $testFile,
            'mon fichier dangereux <script>.jpg',
            'image/jpeg',
            null,
            true
        );

        $filename = $this->service->generateSecureFilename($uploadedFile);

        // Le nom ne doit pas contenir le nom original
        $this->assertStringNotContainsString('dangereux', $filename);
        $this->assertStringNotContainsString('script', $filename);
        $this->assertStringNotContainsString('<', $filename);
        $this->assertStringNotContainsString('>', $filename);

        // Doit être un UUID avec extension
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/[a-f0-9-]{36}\.jpg$/', $filename);
    }

    public function testStripExifData(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('Extension GD requise pour ce test');
        }

        $testFile = $this->createTestJpegFile();

        // S'assurer que le fichier existe avant de nettoyer
        $this->assertFileExists($testFile);

        // Ne devrait pas lever d'exception
        $this->service->stripExifData($testFile);

        // Le fichier doit toujours exister après nettoyage
        $this->assertFileExists($testFile);
    }

    public function testUploadDirectoryPath(): void
    {
        $expectedPath = $this->testDir . '/var/uploads/bon_livraison';
        $this->assertSame($expectedPath, $this->service->getUploadDirectory());
    }

    public function testRejectInvalidMagicBytes(): void
    {
        $this->expectException(InvalidFileException::class);

        // Créer un fichier avec extension .jpg mais contenu texte
        $testFile = $this->testDir . '/fake.jpg';
        file_put_contents($testFile, 'This is not a JPEG file at all');

        $uploadedFile = new UploadedFile(
            $testFile,
            'fake.jpg',
            'text/plain', // finfo détectera le vrai type
            null,
            true
        );

        $this->service->validateFile($uploadedFile);
    }

    private function createTestJpegFile(): string
    {
        $testFile = $this->testDir . '/test_' . uniqid() . '.jpg';

        // Créer une vraie image JPEG avec GD
        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor(100, 100);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
            imagejpeg($image, $testFile, 90);
            imagedestroy($image);
        } else {
            // Fallback: créer un fichier JPEG minimal valide
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

    private function createEtablissement(): Etablissement
    {
        $organisation = new Organisation();
        $organisation->setNom('Test Org');

        $etablissement = new Etablissement();
        $etablissement->setOrganisation($organisation);
        $etablissement->setNom('Test Etablissement');

        return $etablissement;
    }

    private function createUser(): Utilisateur
    {
        $organisation = new Organisation();
        $organisation->setNom('Test Org');

        $user = new Utilisateur();
        $user->setEmail('test@example.com');
        $user->setPassword('hashed_password');
        $user->setOrganisation($organisation);

        return $user;
    }
}
