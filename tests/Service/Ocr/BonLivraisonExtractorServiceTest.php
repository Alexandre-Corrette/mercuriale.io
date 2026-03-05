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
use App\Service\Ocr\OcrMatchingService;
use Doctrine\ORM\EntityManagerInterface;
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
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new BonLivraisonExtractorService(
            $this->anthropicClient,
            $this->entityManager,
            $this->produitFournisseurRepository,
            $this->fournisseurRepository,
            $this->uniteRepository,
            $this->ocrMatchingService,
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
