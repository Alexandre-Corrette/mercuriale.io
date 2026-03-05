<?php

declare(strict_types=1);

namespace App\Tests\Service\Ocr;

use App\Entity\Fournisseur;
use App\Entity\ProduitFournisseur;
use App\Enum\MatchConfidence;
use App\Repository\ProduitFournisseurRepository;
use App\Service\Ocr\OcrMatchingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OcrMatchingServiceTest extends TestCase
{
    private MockObject&ProduitFournisseurRepository $repository;
    private MockObject&LoggerInterface $logger;
    private OcrMatchingService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProduitFournisseurRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new OcrMatchingService($this->repository, $this->logger);
    }

    /**
     * Cas 1 : code article présent + trouvé en mercuriale → EXACT
     */
    public function testExactMatchByCodeArticle(): void
    {
        $fournisseur = $this->createFournisseur('TerreAzur');
        $produit = $this->createProduit('103634', 'Salade mélangée provençale');

        $this->repository
            ->method('findOneBy')
            ->with([
                'fournisseur' => $fournisseur,
                'codeFournisseur' => '103634',
                'actif' => true,
            ])
            ->willReturn($produit);

        $result = $this->service->matchLigne('103634', 'Sal jp mel provençal', $fournisseur);

        $this->assertSame($produit, $result->produitFournisseur);
        $this->assertSame(MatchConfidence::EXACT, $result->confidence);
        $this->assertSame('code_article', $result->matchedBy);
        $this->assertSame(100.0, $result->similarityScore);
        $this->assertTrue($result->isMatched());
    }

    /**
     * Cas 2 : code absent, fuzzy match réussi → FUZZY
     */
    public function testFuzzyMatchWhenNoCode(): void
    {
        $fournisseur = $this->createFournisseur('TerreAzur');
        $produit = $this->createProduit('103634', 'Oignon rouge 60/80 5K France');

        $this->repository
            ->method('findByFournisseur')
            ->with($fournisseur)
            ->willReturn([$produit]);

        $result = $this->service->matchLigne(null, 'Oignon rouge 60/80 5K France', $fournisseur);

        $this->assertSame($produit, $result->produitFournisseur);
        $this->assertSame(MatchConfidence::FUZZY, $result->confidence);
        $this->assertSame('designation', $result->matchedBy);
        $this->assertGreaterThanOrEqual(75.0, $result->similarityScore);
        $this->assertTrue($result->isMatched());
    }

    /**
     * Cas 3 : aucun match → NONE
     */
    public function testNoMatch(): void
    {
        $fournisseur = $this->createFournisseur('TerreAzur');

        $this->repository
            ->method('findOneBy')
            ->willReturn(null);

        $this->repository
            ->method('findByFournisseur')
            ->willReturn([]);

        $result = $this->service->matchLigne('UNKNOWN', 'Produit inexistant xyz', $fournisseur);

        $this->assertNull($result->produitFournisseur);
        $this->assertSame(MatchConfidence::NONE, $result->confidence);
        $this->assertSame('none', $result->matchedBy);
        $this->assertFalse($result->isMatched());
    }

    /**
     * Cas 4 : code non trouvé en base, fuzzy réussi → FUZZY (fallback)
     */
    public function testFallbackToFuzzyWhenCodeNotFound(): void
    {
        $fournisseur = $this->createFournisseur('TerreAzur');
        $produit = $this->createProduit('103634', 'Oignon rouge 60/80 5K France');

        $this->repository
            ->method('findOneBy')
            ->willReturn(null);

        $this->repository
            ->method('findByFournisseur')
            ->with($fournisseur)
            ->willReturn([$produit]);

        $result = $this->service->matchLigne('WRONG_CODE', 'Oignon rouge 60/80 5K France', $fournisseur);

        $this->assertSame($produit, $result->produitFournisseur);
        $this->assertSame(MatchConfidence::FUZZY, $result->confidence);
        $this->assertSame('designation', $result->matchedBy);
    }

    /**
     * Cas 5 : même code chez deux fournisseurs différents → match correct (strict fournisseur)
     */
    public function testSameCodeDifferentFournisseurs(): void
    {
        $fournisseurA = $this->createFournisseur('TerreAzur');
        $fournisseurB = $this->createFournisseur('Le Bihan');

        $produitA = $this->createProduit('ABC123', 'Produit chez TerreAzur');
        $produitB = $this->createProduit('ABC123', 'Produit chez Le Bihan');

        $this->repository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fournisseurA, $fournisseurB, $produitA, $produitB) {
                if ($criteria['fournisseur'] === $fournisseurA && $criteria['codeFournisseur'] === 'ABC123') {
                    return $produitA;
                }
                if ($criteria['fournisseur'] === $fournisseurB && $criteria['codeFournisseur'] === 'ABC123') {
                    return $produitB;
                }
                return null;
            });

        $resultA = $this->service->matchLigne('ABC123', null, $fournisseurA);
        $resultB = $this->service->matchLigne('ABC123', null, $fournisseurB);

        $this->assertSame($produitA, $resultA->produitFournisseur);
        $this->assertSame($produitB, $resultB->produitFournisseur);
        $this->assertNotSame($resultA->produitFournisseur, $resultB->produitFournisseur);
        $this->assertSame(MatchConfidence::EXACT, $resultA->confidence);
        $this->assertSame(MatchConfidence::EXACT, $resultB->confidence);
    }

    /**
     * Cas 6 : seuil fuzzy — 74% → NONE, 76% → FUZZY
     */
    public function testFuzzyThresholdBoundary(): void
    {
        $fournisseur = $this->createFournisseur('TerreAzur');

        // Produit avec désignation très différente → similarité basse
        $produitFaible = $this->createProduit('001', 'XYZABC');
        // Produit avec désignation très proche → similarité haute
        $produitProche = $this->createProduit('002', 'Tomate ronde cat1 FR');

        // Test sous le seuil : désignation sans rapport → NONE
        $this->repository
            ->method('findOneBy')
            ->willReturn(null);

        $this->repository
            ->method('findByFournisseur')
            ->willReturn([$produitFaible]);

        $resultBas = $this->service->matchLigne(null, 'Pomme de terre nouvelle', $fournisseur);
        $this->assertSame(MatchConfidence::NONE, $resultBas->confidence);
        $this->assertFalse($resultBas->isMatched());

        // Test au-dessus du seuil : désignation identique → FUZZY
        $this->repository = $this->createMock(ProduitFournisseurRepository::class);
        $this->service = new OcrMatchingService($this->repository, $this->logger);

        $this->repository
            ->method('findOneBy')
            ->willReturn(null);

        $this->repository
            ->method('findByFournisseur')
            ->willReturn([$produitProche]);

        $resultHaut = $this->service->matchLigne(null, 'Tomate ronde cat1 FR', $fournisseur);
        $this->assertSame(MatchConfidence::FUZZY, $resultHaut->confidence);
        $this->assertTrue($resultHaut->isMatched());
        $this->assertGreaterThanOrEqual(75.0, $resultHaut->similarityScore);
    }

    private function createFournisseur(string $nom): Fournisseur
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $fournisseur->method('getNom')->willReturn($nom);
        $fournisseur->method('getId')->willReturn(rand(1, 1000));

        return $fournisseur;
    }

    private function createProduit(string $code, string $designation): ProduitFournisseur
    {
        $produit = $this->createMock(ProduitFournisseur::class);
        $produit->method('getCodeFournisseur')->willReturn($code);
        $produit->method('getDesignationFournisseur')->willReturn($designation);

        return $produit;
    }
}
