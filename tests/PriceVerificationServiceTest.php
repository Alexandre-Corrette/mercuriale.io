<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CatalogProduct;
use App\Entity\DeliveryReceipt;
use App\Entity\DeliveryReceiptLine;
use App\Entity\Establishment;
use App\Entity\Supplier;
use App\Repository\CatalogProductRepository;
use App\Service\PriceVerificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires — PriceVerificationService
 *
 * Couvre la vérification des prix/quantités d'un BL
 * par rapport à la mercuriale de référence (MERC-2).
 *
 * Seuil d'alerte : 5% (strict : > 5%)
 */
class PriceVerificationServiceTest extends TestCase
{
    private PriceVerificationService $service;

    /** @var CatalogProductRepository&MockObject */
    private CatalogProductRepository $catalogRepo;

    protected function setUp(): void
    {
        $this->catalogRepo = $this->createMock(CatalogProductRepository::class);
        $this->service     = new PriceVerificationService($this->catalogRepo);
    }

    // =========================================================
    // CAS NOMINAUX — TERREAZUR (BL 7830896247)
    // =========================================================

    public function testNoAlertWhenPriceMatchesMercuriale(): void
    {
        // Sal jp mel provencal : PU 7,1100 → mercuriale 7,1100
        $line = $this->makeLine('Sal jp mel provencal 1/2 500gX2', '2.000', '7.1100');
        $this->mockCatalogPrice('Sal jp mel provencal 1/2 500gX2', '7.1100');

        $alerts = $this->service->verifyLine($line);

        $this->assertEmpty($alerts);
    }

    public function testNoAlertWhenVarianceExactly5Percent(): void
    {
        // Prix BL exactement 5% au-dessus → pas d'alerte (seuil strict > 5%)
        $line = $this->makeLine('Oignon rge 60/80 5K c1 FR', '1.000', '3.4020'); // 3,2400 * 1.05
        $this->mockCatalogPrice('Oignon rge 60/80 5K c1 FR', '3.2400');

        $alerts = $this->service->verifyLine($line);

        $this->assertEmpty($alerts);
    }

    public function testAlertWhenVarianceAbove5Percent(): void
    {
        // Prosecco : BL 8,3200 vs mercuriale 7,8320 → +6,23%
        $line = $this->makeLine('75CL PROSECCO PERLINO', '24.000', '8.3200');
        $this->mockCatalogPrice('75CL PROSECCO PERLINO', '7.8320');

        $alerts = $this->service->verifyLine($line);

        $this->assertNotEmpty($alerts);
        $this->assertSame('price_variance', $alerts[0]['type']);
        $this->assertEqualsWithDelta(6.23, $alerts[0]['variance_pct'], 0.01);
    }

    public function testAlertWhenVarianceAbove5PercentGrimbergen(): void
    {
        // Grimbergen : BL 3,1680 vs mercuriale 2,9190 → +8,53%
        $line = $this->makeLine('FUT 20L GRIMBERGEN BLONDE', '3.000', '3.1680');
        $this->mockCatalogPrice('FUT 20L GRIMBERGEN BLONDE', '2.9190');

        $alerts = $this->service->verifyLine($line);

        $this->assertNotEmpty($alerts);
        $this->assertEqualsWithDelta(8.53, $alerts[0]['variance_pct'], 0.01);
    }

    // =========================================================
    // LIGNES GRATUITES (montant = 0)
    // =========================================================

    public function testNoAlertOnGratuitLine(): void
    {
        // 25CL CARAIBOS NECTAR ABRICOT VPX12 — GRATUIT (prix = 0)
        $line = $this->makeLine('25CL CARAIBOS NECTAR ABRICOT VPX12', '2.000', '0.0000');
        $this->mockCatalogPrice('25CL CARAIBOS NECTAR ABRICOT VPX12', '0.8500');

        $alerts = $this->service->verifyLine($line);

        // Une ligne gratuite ne doit pas générer d'alerte de prix
        $this->assertEmpty($alerts);
    }

    // =========================================================
    // LIGNES DE REMISE
    // =========================================================

    public function testNoAlertOnRemiseLine(): void
    {
        // "REMISE 10% (soit -3,13)" → ligne de remise, qté 0, montant négatif
        $line = $this->makeLine('REMISE 10% (soit -3,13)', '0.000', '0.0000');
        $line->setLineAmount('-3.13');

        $alerts = $this->service->verifyLine($line);

        $this->assertEmpty($alerts);
    }

    // =========================================================
    // PRODUIT ABSENT DE LA MERCURIALE
    // =========================================================

    public function testAlertWhenProductNotInCatalog(): void
    {
        // Produit inconnu de la mercuriale → alerte "product_not_in_catalog"
        $line = $this->makeLine('PRODUIT INCONNU XYZ', '1.000', '15.0000');
        $this->catalogRepo
            ->method('findBestMatch')
            ->willReturn(null);

        $alerts = $this->service->verifyLine($line);

        $this->assertNotEmpty($alerts);
        $this->assertSame('product_not_in_catalog', $alerts[0]['type']);
    }

    // =========================================================
    // VÉRIFICATION COMPLÈTE D'UN BL
    // =========================================================

    public function testVerifyFullBlReturnsAllAlerts(): void
    {
        $establishment = new Establishment();
        $supplier      = new Supplier();
        $bl            = new DeliveryReceipt();
        $bl->setEstablishment($establishment);
        $bl->setSupplier($supplier);

        // 2 lignes OK + 1 ligne avec écart prix
        $bl->addLine($this->makeLine('Sal jp mel provencal 1/2 500gX2', '2.000', '7.1100'));
        $bl->addLine($this->makeLine('Oignon rge 60/80 5K c1 FR', '1.000', '3.2400'));
        $bl->addLine($this->makeLine('75CL PROSECCO PERLINO', '24.000', '8.3200'));

        $this->catalogRepo->method('findBestMatch')->willReturnCallback(
            fn(string $designation) => match (true) {
                str_contains($designation, 'provencal') => $this->makeCatalogProduct('7.1100'),
                str_contains($designation, 'Oignon')    => $this->makeCatalogProduct('3.2400'),
                str_contains($designation, 'PROSECCO')  => $this->makeCatalogProduct('7.8320'),
                default => null,
            }
        );

        $result = $this->service->verifyDeliveryReceipt($bl);

        $this->assertCount(1, $result->alerts);
        $this->assertSame('price_variance', $result->alerts[0]['type']);
        $this->assertStringContainsString('PROSECCO', $result->alerts[0]['line']);
    }

    public function testVerifyBlWithNoAlertsReturnsEmptyAlerts(): void
    {
        // BL TerreAzur 7830900916 — tous les prix conformes
        $establishment = new Establishment();
        $supplier      = new Supplier();
        $bl            = new DeliveryReceipt();
        $bl->setEstablishment($establishment);
        $bl->setSupplier($supplier);

        $lines = [
            ['Sal jp mel provencal 1/2 500gX2', '3.000', '7.1100'],
            ['Pdt agata gren ct 12,5K c1 FR',   '1.000', '2.6100'],
            ['Echalion 30/50 5K c1 FR',          '1.000', '3.0000'],
            ['Poivron rouge 90/110 5K c1 MA',    '1.000', '2.8800'],
        ];

        foreach ($lines as [$designation, $qty, $price]) {
            $bl->addLine($this->makeLine($designation, $qty, $price));
        }

        $this->catalogRepo->method('findBestMatch')->willReturnCallback(
            fn(string $d) => $this->makeCatalogProduct(
                match (true) {
                    str_contains($d, 'provencal') => '7.1100',
                    str_contains($d, 'agata')     => '2.6100',
                    str_contains($d, 'Echalion')  => '3.0000',
                    str_contains($d, 'Poivron')   => '2.8800',
                    default                       => '0.0000',
                }
            )
        );

        $result = $this->service->verifyDeliveryReceipt($bl);

        $this->assertEmpty($result->alerts);
        $this->assertSame('verified', $result->status);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function makeLine(string $designation, string $qty, string $unitPrice): DeliveryReceiptLine
    {
        $line = new DeliveryReceiptLine();
        $line->setDesignation($designation);
        $line->setQuantity($qty);
        $line->setUnitPrice($unitPrice);
        $line->setLineAmount((string) round((float) $qty * (float) $unitPrice, 2));
        return $line;
    }

    private function mockCatalogPrice(string $designation, string $price): void
    {
        $this->catalogRepo
            ->method('findBestMatch')
            ->with($this->stringContains(substr($designation, 0, 10)))
            ->willReturn($this->makeCatalogProduct($price));
    }

    private function makeCatalogProduct(string $price): CatalogProduct
    {
        $p = new CatalogProduct();
        $p->setUnitPrice($price);
        return $p;
    }
}
