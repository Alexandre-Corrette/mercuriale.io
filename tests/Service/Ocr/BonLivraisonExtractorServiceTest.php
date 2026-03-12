<?php

declare(strict_types=1);

namespace App\Tests\Service\Ocr;

use App\DTO\MatchResult;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Enum\MatchConfidence;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\UniteRepository;
use App\Service\Ocr\AnthropicClient;
use App\Service\Ocr\BonLivraisonExtractorService;
use App\Service\Ocr\ExtractionValidator;
use App\Service\Ocr\OcrMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BonLivraisonExtractorServiceTest extends TestCase
{
    private MockObject&AnthropicClient $anthropicClient;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&ProduitFournisseurRepository $produitFournisseurRepository;
    private MockObject&FournisseurRepository $fournisseurRepository;
    private MockObject&UniteRepository $uniteRepository;
    private MockObject&OcrMatchingService $ocrMatchingService;
    private MockObject&ExtractionValidator $extractionValidator;
    private MockObject&LoggerInterface $logger;
    private BonLivraisonExtractorService $service;

    protected function setUp(): void
    {
        $this->anthropicClient = $this->createMock(AnthropicClient::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->produitFournisseurRepository = $this->createMock(ProduitFournisseurRepository::class);
        $this->fournisseurRepository = $this->createMock(FournisseurRepository::class);
        $this->uniteRepository = $this->createMock(UniteRepository::class);
        $this->ocrMatchingService = $this->createMock(OcrMatchingService::class);
        $this->extractionValidator = $this->createMock(ExtractionValidator::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->extractionValidator->method('validate')->willReturn([]);

        $this->service = new BonLivraisonExtractorService(
            $this->anthropicClient,
            $this->entityManager,
            $this->produitFournisseurRepository,
            $this->fournisseurRepository,
            $this->uniteRepository,
            $this->ocrMatchingService,
            $this->extractionValidator,
            $this->logger,
            '/tmp/test-project',
        );
    }

    public function testExtractWithNoImage(): void
    {
        $bl = $this->createBonLivraison();
        $bl->setImagePath(null);

        $result = $this->service->extract($bl);

        $this->assertFalse($result->success);
        $this->assertContains('Aucune image associée au bon de livraison', $result->warnings);
    }

    public function testExtractWithValidResponse(): void
    {
        // Créer un fichier temporaire pour simuler l'image
        $tempFile = tempnam(sys_get_temp_dir(), 'bl_test_');
        file_put_contents($tempFile, 'fake image content');

        $bl = $this->createBonLivraison();
        $bl->setImagePath($tempFile);

        // Mock de la réponse de Claude (BL FoodFlow simulé)
        $mockResponse = $this->getMockFoodFlowResponse();

        $this->anthropicClient
            ->expects($this->once())
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($mockResponse),
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        // Mock OcrMatchingService — retourne NONE par défaut
        $this->ocrMatchingService
            ->method('matchLigne')
            ->willReturn(new MatchResult(null, MatchConfidence::NONE, 'none'));

        // Mock de l'unité pièce
        $unite = $this->createMock(Unite::class);
        $unite->method('getCode')->willReturn('p');

        $this->uniteRepository
            ->method('findOneBy')
            ->willReturn($unite);

        $this->entityManager
            ->expects($this->atLeastOnce())
            ->method('persist');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $result = $this->service->extract($bl);

        $this->assertTrue($result->success);
        $this->assertEquals('haute', $result->confiance);
        $this->assertCount(3, $result->lignes);
        $this->assertGreaterThanOrEqual(0, $result->tempsExtraction);

        // Nettoyer
        unlink($tempFile);
    }

    public function testExtractWithProductMatching(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bl_test_');
        file_put_contents($tempFile, 'fake image content');

        $bl = $this->createBonLivraison();
        $bl->setImagePath($tempFile);

        $mockResponse = $this->getMockFoodFlowResponse();

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => json_encode($mockResponse),
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        // Mock OcrMatchingService : match exact sur FF-000047, NONE sur les autres
        $produitFournisseur = $this->createMock(ProduitFournisseur::class);

        $this->ocrMatchingService
            ->method('matchLigne')
            ->willReturnCallback(function (?string $code, ?string $designation) use ($produitFournisseur) {
                if ($code === 'FF-000047') {
                    return new MatchResult($produitFournisseur, MatchConfidence::EXACT, 'code_article', 100.0);
                }

                return new MatchResult(null, MatchConfidence::NONE, 'none');
            });

        $unite = $this->createMock(Unite::class);
        $unite->method('getCode')->willReturn('p');

        $this->uniteRepository
            ->method('findOneBy')
            ->willReturn($unite);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $result = $this->service->extract($bl);

        $this->assertTrue($result->success);
        // Deux produits non matchés (FF-000141 et FF-000234)
        $this->assertCount(2, $result->produitsNonMatches);

        $nonMatchedCodes = array_column($result->produitsNonMatches, 'code');
        $this->assertContains('FF-000141', $nonMatchedCodes);
        $this->assertContains('FF-000234', $nonMatchedCodes);

        // Vérifier la structure des entrées non matchées
        foreach ($result->produitsNonMatches as $nonMatch) {
            $this->assertArrayHasKey('code', $nonMatch);
            $this->assertArrayHasKey('designation', $nonMatch);
            $this->assertArrayHasKey('confidence', $nonMatch);
            $this->assertSame('NONE', $nonMatch['confidence']);
        }

        unlink($tempFile);
    }

    public function testExtractWithMalformedJson(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bl_test_');
        file_put_contents($tempFile, 'fake image content');

        $bl = $this->createBonLivraison();
        $bl->setImagePath($tempFile);

        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => 'invalid json {{{',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]);

        $result = $this->service->extract($bl);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->warnings);

        unlink($tempFile);
    }

    public function testExtractWithMarkdownWrappedJson(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'bl_test_');
        file_put_contents($tempFile, 'fake image content');

        $bl = $this->createBonLivraison();
        $bl->setImagePath($tempFile);

        $mockResponse = $this->getMockFoodFlowResponse();

        // Simuler une réponse avec markdown
        $this->anthropicClient
            ->method('analyzeImage')
            ->willReturn([
                'content' => "```json\n" . json_encode($mockResponse) . "\n```",
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]);

        // Mock OcrMatchingService — retourne NONE par défaut
        $this->ocrMatchingService
            ->method('matchLigne')
            ->willReturn(new MatchResult(null, MatchConfidence::NONE, 'none'));

        $unite = $this->createMock(Unite::class);
        $unite->method('getCode')->willReturn('p');

        $this->uniteRepository
            ->method('findOneBy')
            ->willReturn($unite);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $result = $this->service->extract($bl);

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->lignes);

        unlink($tempFile);
    }

    private function createBonLivraison(): BonLivraison
    {
        $etablissement = $this->createMock(Etablissement::class);
        $etablissement->method('getId')->willReturn(1);

        $fournisseur = $this->createMock(Fournisseur::class);
        $fournisseur->method('getId')->willReturn(1);
        $fournisseur->method('getNom')->willReturn('FoodFlow');

        $bl = new BonLivraison();
        $bl->setEtablissement($etablissement);
        $bl->setFournisseur($fournisseur);
        $bl->setDateLivraison(new \DateTimeImmutable('2026-01-31'));

        return $bl;
    }

    // =============================================
    // MERC-108 — Supplier name normalization tests
    // =============================================

    #[DataProvider('normalizeNameProvider')]
    public function testNormalizeName(string $input, string $expected): void
    {
        $this->assertSame($expected, BonLivraisonExtractorService::normalizeName($input));
    }

    public static function normalizeNameProvider(): iterable
    {
        yield 'lowercase' => ['TERREAZUR', 'terreazur'];
        yield 'accents removed' => ['Métro', 'metro'];
        yield 'accents complex' => ['Café Hôtel Résidence', 'cafe hotel residence'];
        yield 'tirets replaced' => ['Terre-Azur', 'terre azur'];
        yield 'underscores replaced' => ['Terre_Azur', 'terre azur'];
        yield 'dots replaced' => ['S.A.S.', 's a s'];
        yield 'multiple spaces collapsed' => ['Brake   France', 'brake france'];
        yield 'trim whitespace' => ['  Metro  ', 'metro'];
        yield 'mixed accents and tirets' => ['Café-Résidence_Hôtel', 'cafe residence hotel'];
        yield 'already clean' => ['pomona', 'pomona'];
    }

    // =============================================
    // MERC-108 — Supplier detection tests
    // =============================================

    #[DataProvider('supplierDetectionProvider')]
    public function testSupplierDetection(string $supplierName, string $expectedContextSubstring): void
    {
        $bl = new BonLivraison();
        $etablissement = $this->createMock(Etablissement::class);
        $bl->setEtablissement($etablissement);

        $fournisseur = $this->createMock(Fournisseur::class);
        $fournisseur->method('getId')->willReturn(1);
        $fournisseur->method('getNom')->willReturn($supplierName);
        $bl->setFournisseur($fournisseur);
        $bl->setDateLivraison(new \DateTimeImmutable());

        // Use reflection to test the private buildExtractionPrompt method
        $reflection = new \ReflectionMethod($this->service, 'buildExtractionPrompt');
        $prompt = $reflection->invoke($this->service, $bl);

        $this->assertStringContainsString($expectedContextSubstring, $prompt);
    }

    public static function supplierDetectionProvider(): iterable
    {
        // TerreAzur variants
        yield 'TerreAzur exact' => ['TerreAzur', 'TERREAZUR'];
        yield 'Terre Azur spaced' => ['Terre Azur', 'TERREAZUR'];
        yield 'Terre-Azur hyphen' => ['Terre-Azur', 'TERREAZUR'];
        yield 'TERREAZUR uppercase' => ['TERREAZUR', 'TERREAZUR'];
        yield 'Pomona TerreAzur group' => ['Pomona TerreAzur', 'TERREAZUR'];
        yield 'Pomona alone' => ['Pomona', 'TERREAZUR'];

        // Le Bihan variants
        yield 'Le Bihan' => ['Le Bihan', 'LE BIHAN TMEG'];
        yield 'TMEG' => ['TMEG Distribution', 'LE BIHAN TMEG'];

        // Brake variants
        yield 'Brake France' => ['Brake France', 'BRAKE'];
        yield 'BRAKE SAS' => ['BRAKE SAS', 'BRAKE'];
        yield 'brake lowercase' => ['brake', 'BRAKE'];

        // Metro variants
        yield 'METRO Cash & Carry' => ['METRO Cash & Carry', 'METRO'];
        yield 'Métro accented' => ['Métro', 'METRO'];
        yield 'metro lowercase' => ['metro', 'METRO'];

        // Transgourmet variants
        yield 'Transgourmet' => ['Transgourmet', 'TRANSGOURMET'];
        yield 'Transgourmet Opérations' => ['Transgourmet Opérations', 'TRANSGOURMET'];
        yield 'PROMOCASH' => ['PROMOCASH', 'TRANSGOURMET'];
        yield 'Promocash lowercase' => ['promocash', 'TRANSGOURMET'];

        // Sysco variants
        yield 'Sysco France' => ['Sysco France', 'SYSCO'];
        yield 'SYSCO SAS' => ['SYSCO SAS', 'SYSCO'];
        yield 'Davigel' => ['Davigel', 'SYSCO'];

        // Generic fallback
        yield 'Unknown supplier' => ['Fournisseur Inconnu SARL', 'GUIDE D\'EXTRACTION UNIVERSEL'];
        yield 'Random name' => ['Jean Dupont Primeurs', 'GUIDE D\'EXTRACTION UNIVERSEL'];
    }

    public function testGenericContextIsComprehensive(): void
    {
        $bl = new BonLivraison();
        $etablissement = $this->createMock(Etablissement::class);
        $bl->setEtablissement($etablissement);
        $bl->setDateLivraison(new \DateTimeImmutable());
        // No fournisseur → generic context

        $reflection = new \ReflectionMethod($this->service, 'buildExtractionPrompt');
        $prompt = $reflection->invoke($this->service, $bl);

        // Verify the generic context covers all critical sections
        $this->assertStringContainsString('IDENTIFIER LA STRUCTURE', $prompt);
        $this->assertStringContainsString('QUANTITÉS', $prompt);
        $this->assertStringContainsString('UNITÉS FRANÇAISES', $prompt);
        $this->assertStringContainsString('TOTAUX ET RÉCAPITULATIFS', $prompt);
        $this->assertStringContainsString('MULTI-PAGES', $prompt);
    }

    // =============================================
    // MERC-109 — Unit mapping tests
    // =============================================

    #[DataProvider('uniteMappingProvider')]
    public function testUniteMapping(string $input, string $expectedCode): void
    {
        // Access UNITE_MAPPING via reflection
        $reflection = new \ReflectionClass(BonLivraisonExtractorService::class);
        $mapping = $reflection->getConstant('UNITE_MAPPING');

        $normalizedInput = strtolower(trim($input));
        $result = $mapping[$normalizedInput] ?? 'PU';

        $this->assertSame($expectedCode, $result, "Unit '$input' should map to '$expectedCode'");
    }

    public static function uniteMappingProvider(): iterable
    {
        // Existing mappings (regression)
        yield 'kg' => ['kg', 'KG'];
        yield 'KG uppercase' => ['KG', 'KG'];
        yield 'litre' => ['litre', 'L'];
        yield 'bouteille' => ['bouteille', 'BOT'];
        yield 'colis' => ['colis', 'COL'];
        yield 'carton' => ['carton', 'CAR'];
        yield 'sac' => ['sac', 'SAC'];
        yield 'barquette' => ['barquette', 'BQT'];
        yield 'piece' => ['piece', 'PU'];

        // New mappings (MERC-109)
        yield 'pack' => ['pack', 'PCK'];
        yield 'pck' => ['pck', 'PCK'];
        yield 'palette' => ['palette', 'PAL'];
        yield 'pal' => ['pal', 'PAL'];
        yield 'plateau' => ['plateau', 'PLT'];
        yield 'plt' => ['plt', 'PLT'];
        yield 'bidon' => ['bidon', 'BDN'];
        yield 'bdn' => ['bdn', 'BDN'];
        yield 'jerrycan' => ['jerrycan', 'JER'];
        yield 'boite' => ['boite', 'BTE'];
        yield 'boîte accented' => ['boîte', 'BTE'];
        yield 'bte' => ['bte', 'BTE'];
        yield 'sachet' => ['sachet', 'SAC'];
        yield 'flacon' => ['flacon', 'BOT'];
        yield 'btl brake' => ['btl', 'BOT'];
        yield 'uvc transgourmet' => ['uvc', 'PU'];
        yield 'douzaine' => ['douzaine', 'PU'];
        yield 'paquet' => ['paquet', 'PU'];
        yield 'dl volume' => ['dl', 'L'];
        yield 'hectolitre' => ['hl', 'L'];
        yield 'tonne' => ['tonne', 'KG'];
        yield 'fût accented' => ['fût', 'FUT'];

        // Fallback
        yield 'unknown unit' => ['bidule', 'PU'];
    }

    private function getMockFoodFlowResponse(): array
    {
        return [
            'fournisseur' => [
                'nom' => 'FoodFlow',
                'email' => 'compta@foodflow.fr',
                'telephone' => '01 23 45 67 89',
                'adresse' => '123 Rue des Primeurs, 75001 Paris',
                'siret' => '12345678901234',
            ],
            'bon_livraison' => [
                'numero_bl' => 'BL-2026-0001',
                'numero_commande' => 'S354968',
                'date_livraison' => '2026-01-31',
                'client' => 'ESCALE PARMENTIER CLO',
            ],
            'lignes' => [
                [
                    'code_produit' => 'FF-000047',
                    'designation' => 'Myrtille barquette 125Gr',
                    'quantite_commandee' => 3.0,
                    'quantite_livree' => 3.0,
                    'unite' => 'p',
                    'prix_unitaire' => 1.99,
                    'total_ligne' => 5.97,
                ],
                [
                    'code_produit' => 'FF-000141',
                    'designation' => 'Poivron rouge',
                    'quantite_commandee' => 5.0,
                    'quantite_livree' => 5.0,
                    'unite' => 'kg',
                    'prix_unitaire' => 3.23,
                    'total_ligne' => 16.15,
                ],
                [
                    'code_produit' => 'FF-000234',
                    'designation' => 'Salade batavia',
                    'quantite_commandee' => 10.0,
                    'quantite_livree' => 8.0,
                    'unite' => 'p',
                    'prix_unitaire' => 0.85,
                    'total_ligne' => 6.80,
                ],
            ],
            'total_ht' => 28.92,
            'nombre_lignes' => 3,
            'confiance' => 'haute',
            'remarques' => [],
        ];
    }
}
