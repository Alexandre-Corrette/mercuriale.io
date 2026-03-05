<?php

declare(strict_types=1);

namespace App\Tests\Service\Ocr;

use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Enum\SourceFacture;
use App\Enum\StatutFacture;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Service\Ocr\AnthropicClient;
use App\Service\Ocr\FactureExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FactureExtractorServiceTest extends TestCase
{
    private MockObject&AnthropicClient $anthropicClient;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&FournisseurRepository $fournisseurRepository;
    private MockObject&ProduitFournisseurRepository $produitFournisseurRepository;
    private MockObject&LoggerInterface $logger;
    private FactureExtractorService $service;
    private string $testDir;

    protected function setUp(): void
    {
        $this->anthropicClient = $this->createMock(AnthropicClient::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fournisseurRepository = $this->createMock(FournisseurRepository::class);
        $this->produitFournisseurRepository = $this->createMock(ProduitFournisseurRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->testDir = sys_get_temp_dir() . '/mercuriale_ocr_test_' . uniqid();

        mkdir($this->testDir . '/var/factures', 0755, true);

        $this->service = new FactureExtractorService(
            $this->anthropicClient,
            $this->entityManager,
            $this->fournisseurRepository,
            $this->produitFournisseurRepository,
            $this->logger,
            $this->testDir,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function testExtractWithNoDocument(): void
    {
        $facture = $this->createFacture();
        $facture->setDocumentOriginalPath(null);

        $result = $this->service->extract($facture);

        $this->assertFalse($result['success']);
        $this->assertContains('Aucun document associé à la facture', $result['warnings']);
    }

    public function testExtractWithMissingFile(): void
    {
        $facture = $this->createFacture();
        $facture->setDocumentOriginalPath('2025/08/nonexistent.pdf');

        $result = $this->service->extract($facture);

        $this->assertFalse($result['success']);
        $this->assertContains('Aucun document associé à la facture', $result['warnings']);
    }

    public function testExtractWithValidResponse(): void
    {
        $facture = $this->createFactureWithFile();

        $mockResponse = $this->getMockTerreAzurResponse();

        $this->anthropicClient
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($mockResponse),
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 800],
            ]);

        $this->entityManager
            ->expects($this->atLeastOnce())
            ->method('persist');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $result = $this->service->extract($facture);

        $this->assertTrue($result['success']);
        $this->assertSame('FAC-2025-0247', $facture->getNumeroFacture());
        $this->assertSame(StatutFacture::RECUE, $facture->getStatut());
        $this->assertSame('401.59', $facture->getMontantHt());
        $this->assertSame('22.09', $facture->getMontantTva());
        $this->assertSame('423.68', $facture->getMontantTtc());
        $this->assertNotNull($facture->getOcrProcessedAt());
        $this->assertNotNull($facture->getOcrRawData());
        $this->assertCount(3, $facture->getLignes());
    }

    public function testExtractSetsFournisseurInfo(): void
    {
        $facture = $this->createFactureWithFile();

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($this->getMockTerreAzurResponse()),
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->service->extract($facture);

        $this->assertSame('TerreAzur Aquitaine', $facture->getFournisseurNom());
        $this->assertSame('FR55204499200', $facture->getFournisseurTva());
        $this->assertSame('552044992', $facture->getFournisseurSiren());
    }

    public function testExtractWithFournisseurMatching(): void
    {
        $facture = $this->createFactureWithFile();
        // No fournisseur pre-set → should try matching
        $facture->setFournisseur(null);

        $fournisseur = new Fournisseur();
        $fournisseur->setNom('TerreAzur Aquitaine');
        $fournisseur->setCode('TERRA');

        $this->fournisseurRepository
            ->method('findByOrganisation')
            ->willReturn([$fournisseur]);

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($this->getMockTerreAzurResponse()),
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->service->extract($facture);

        $this->assertSame($fournisseur, $facture->getFournisseur());
    }

    public function testExtractWithMalformedJson(): void
    {
        $facture = $this->createFactureWithFile();

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => 'invalid json {{{',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]);

        $result = $this->service->extract($facture);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testExtractWithMarkdownWrappedJson(): void
    {
        $facture = $this->createFactureWithFile();

        $mockResponse = $this->getMockTerreAzurResponse();

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => "```json\n" . json_encode($mockResponse) . "\n```",
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 800],
            ]);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $result = $this->service->extract($facture);

        $this->assertTrue($result['success']);
        $this->assertSame('FAC-2025-0247', $facture->getNumeroFacture());
        $this->assertCount(3, $facture->getLignes());
    }

    public function testExtractDetectsLineTotalMismatch(): void
    {
        $facture = $this->createFactureWithFile();

        $response = $this->getMockTerreAzurResponse();
        // Introduce a line total mismatch: 2 × 7.11 = 14.22, not 99.99
        $response['lignes'][0]['montant_ligne'] = 99.99;

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($response),
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $result = $this->service->extract($facture);

        $this->assertTrue($result['success']);
        // Should have a warning about the total mismatch
        $hasWarning = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'total calculé')) {
                $hasWarning = true;
                break;
            }
        }
        $this->assertTrue($hasWarning, 'Expected a line total mismatch warning');
    }

    // ── Helpers ──────────────────────────────────────────────

    private function createFacture(): FactureFournisseur
    {
        $organisation = new Organisation();
        $organisation->setNom('Test Org');

        $etablissement = new Etablissement();
        $etablissement->setOrganisation($organisation);
        $etablissement->setNom('Test Etab');

        $facture = new FactureFournisseur();
        $facture->setSource(SourceFacture::UPLOAD_OCR);
        $facture->setStatut(StatutFacture::BROUILLON);
        $facture->setEtablissement($etablissement);

        return $facture;
    }

    private function createFactureWithFile(): FactureFournisseur
    {
        $facture = $this->createFacture();

        // Create a fake document file
        $docPath = '2025/08/test-facture.jpg';
        $fullPath = $this->testDir . '/var/factures/' . $docPath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, 'fake image content');

        $facture->setDocumentOriginalPath($docPath);
        $facture->setFichierOriginalNom('facture-terra.jpg');

        return $facture;
    }

    private function getMockTerreAzurResponse(): array
    {
        return [
            'fournisseur' => [
                'nom' => 'TerreAzur Aquitaine',
                'adresse' => '110 Quai de Paludate, 33080 Bordeaux',
                'telephone' => '05 56 49 99 00',
                'siret' => '55204499200337',
                'siren' => '552044992',
                'tva_intracom' => 'FR55204499200',
            ],
            'acheteur' => [
                'nom' => 'Guinguette du Château',
                'tva_intracom' => null,
            ],
            'document' => [
                'type' => 'FACTURE',
                'numero' => 'FAC-2025-0247',
                'date_emission' => '2025-08-26',
                'date_echeance' => '2025-09-05',
                'numero_commande' => null,
                'numero_bl' => '7830896247',
                'devise' => 'EUR',
            ],
            'lignes' => [
                [
                    'code_article' => '103634',
                    'designation' => 'Sal jp mel provençal ½ 500gX2 100% FR',
                    'quantite' => 2.0,
                    'unite' => 'KG',
                    'prix_unitaire' => 7.11,
                    'taux_tva' => 5.5,
                    'montant_ligne' => 14.22,
                ],
                [
                    'code_article' => '106535',
                    'designation' => 'Oignon rge 60/80 5K c1 FR',
                    'quantite' => 5.0,
                    'unite' => 'KG',
                    'prix_unitaire' => 3.24,
                    'taux_tva' => 5.5,
                    'montant_ligne' => 16.20,
                ],
                [
                    'code_article' => '114412',
                    'designation' => 'Ft dorade roy el 130/180 AP gr SA 20P',
                    'quantite' => 6.0,
                    'unite' => 'KG',
                    'prix_unitaire' => 20.045,
                    'taux_tva' => 5.5,
                    'montant_ligne' => 120.27,
                ],
            ],
            'totaux' => [
                'total_ht' => 401.59,
                'remise_ht' => null,
                'total_ht_net' => 401.59,
                'tva' => [
                    ['taux' => 5.5, 'base' => 401.59, 'montant' => 22.09],
                ],
                'total_tva' => 22.09,
                'total_ttc' => 423.68,
                'consignes' => null,
                'deconsignes' => null,
                'droits_accise' => null,
                'net_a_payer' => 423.68,
            ],
            'confiance' => 'haute',
            'remarques' => [],
        ];
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
