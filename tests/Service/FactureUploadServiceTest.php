<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Enum\SourceFacture;
use App\Enum\StatutFacture;
use App\Exception\InvalidFileException;
use App\Service\Upload\FactureUploadService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FactureUploadServiceTest extends TestCase
{
    private FactureUploadService $service;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&LoggerInterface $logger;
    private string $testDir;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->testDir = sys_get_temp_dir() . '/mercuriale_facture_test_' . uniqid();

        mkdir($this->testDir, 0755, true);

        $this->service = new FactureUploadService(
            $this->entityManager,
            $this->logger,
            $this->testDir,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function testUploadValidJpegFile(): void
    {
        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile(
            $testFile,
            'facture-test.jpg',
            'image/jpeg',
            null,
            true,
        );

        $etablissement = $this->createEtablissement();
        $user = $this->createUser();

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $facture = $this->service->upload($uploadedFile, $etablissement, $user);

        $this->assertNotNull($facture->getDocumentOriginalPath());
        $this->assertSame($etablissement, $facture->getEtablissement());
        $this->assertSame($user, $facture->getCreatedBy());
        $this->assertSame(SourceFacture::UPLOAD_OCR, $facture->getSource());
        $this->assertSame(StatutFacture::BROUILLON, $facture->getStatut());
        $this->assertSame('facture-test.jpg', $facture->getFichierOriginalNom());
    }

    public function testUploadWithFournisseur(): void
    {
        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile($testFile, 'facture.jpg', 'image/jpeg', null, true);

        $etablissement = $this->createEtablissement();
        $user = $this->createUser();
        $fournisseur = new Fournisseur();
        $fournisseur->setNom('TerreAzur');
        $fournisseur->setCode('TERRA');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $facture = $this->service->upload($uploadedFile, $etablissement, $user, $fournisseur);

        $this->assertSame($fournisseur, $facture->getFournisseur());
        $this->assertSame('TerreAzur', $facture->getFournisseurNom());
    }

    public function testUploadValidPdfFile(): void
    {
        $testFile = $this->createTestPdfFile();

        $uploadedFile = new UploadedFile($testFile, 'facture.pdf', 'application/pdf', null, true);

        $etablissement = $this->createEtablissement();
        $user = $this->createUser();

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $facture = $this->service->upload($uploadedFile, $etablissement, $user);

        $this->assertStringEndsWith('.pdf', $facture->getDocumentOriginalPath());
    }

    public function testRejectFileTooLarge(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('trop volumineux');

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

        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'This is a text file');

        $uploadedFile = new UploadedFile($testFile, 'test.txt', 'text/plain', null, true);

        $this->service->validateFile($uploadedFile);
    }

    public function testRejectPhpFileDisguisedAsImage(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('suspect');

        $testFile = $this->createTestJpegFile();
        $content = file_get_contents($testFile);
        file_put_contents($testFile, $content . '<?php echo "hacked"; ?>');

        $uploadedFile = new UploadedFile($testFile, 'facture.jpg', 'image/jpeg', null, true);

        $this->service->validateFile($uploadedFile);
    }

    public function testRejectScriptInjection(): void
    {
        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('suspect');

        $testFile = $this->createTestJpegFile();
        $content = file_get_contents($testFile);
        file_put_contents($testFile, $content . '<script>alert("xss")</script>');

        $uploadedFile = new UploadedFile($testFile, 'facture.jpg', 'image/jpeg', null, true);

        $this->service->validateFile($uploadedFile);
    }

    public function testRejectInvalidMagicBytes(): void
    {
        $this->expectException(InvalidFileException::class);

        $testFile = $this->testDir . '/fake.jpg';
        file_put_contents($testFile, 'This is not a JPEG file at all');

        $uploadedFile = new UploadedFile($testFile, 'fake.jpg', 'text/plain', null, true);

        $this->service->validateFile($uploadedFile);
    }

    public function testUploadDirectoryPath(): void
    {
        $expectedPath = $this->testDir . '/var/factures';
        $this->assertSame($expectedPath, $this->service->getUploadDirectory());
    }

    public function testGenerateSecureFilename(): void
    {
        $testFile = $this->createTestJpegFile();

        $uploadedFile = new UploadedFile(
            $testFile,
            'facture dangereuse <script>.jpg',
            'image/jpeg',
            null,
            true,
        );

        // Use reflection to access private method
        $reflection = new \ReflectionMethod($this->service, 'generateSecureFilename');

        $filename = $reflection->invoke($this->service, $uploadedFile);

        $this->assertStringNotContainsString('dangereuse', $filename);
        $this->assertStringNotContainsString('script', $filename);
        $this->assertStringNotContainsString('<', $filename);
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/[a-f0-9-]{36}\.jpg$/', $filename);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function createTestJpegFile(): string
    {
        $testFile = $this->testDir . '/test_' . uniqid() . '.jpg';

        if (extension_loaded('gd')) {
            $image = imagecreatetruecolor(100, 100);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
            imagejpeg($image, $testFile, 90);
            imagedestroy($image);
        } else {
            $minimalJpeg = base64_decode(
                '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRof'
                . 'Hh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwh'
                . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAAR'
                . 'CAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAA'
                . 'AAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMB'
                . 'AAIRAxEAPwCwAB//2Q=='
            );
            file_put_contents($testFile, $minimalJpeg);
        }

        return $testFile;
    }

    private function createTestPdfFile(): string
    {
        $testFile = $this->testDir . '/test_' . uniqid() . '.pdf';
        // Minimal valid PDF
        $pdf = "%PDF-1.0\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/MediaBox[0 0 3 3]>>endobj\n"
            . "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n"
            . "0000000058 00000 n \n0000000115 00000 n \n"
            . "trailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
        file_put_contents($testFile, $pdf);

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
}
