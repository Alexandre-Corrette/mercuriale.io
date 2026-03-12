<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeliveryReceipt;
use App\Entity\DeliveryReceiptLine;
use App\Entity\Establishment;
use App\Entity\Supplier;
use App\Entity\SupplierInvoice;
use App\Entity\SupplierInvoiceLine;
use App\Repository\DeliveryReceiptRepository;
use App\Service\InvoiceMatchingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires — InvoiceMatchingService
 *
 * Couvre :
 *   - Rapprochement par numéro de BL (match direct)
 *   - Rapprochement par fournisseur + plage de dates + montant approchant
 *   - Cas "aucun BL candidat" (facture orpheline)
 *   - Calcul des écarts prix (seuil 5%)
 *   - Calcul des écarts quantité
 *   - Détection des lignes absentes du BL
 *   - Calcul du matchScore
 */
class InvoiceMatchingServiceTest extends TestCase
{
    private InvoiceMatchingService $service;

    /** @var DeliveryReceiptRepository&MockObject */
    private DeliveryReceiptRepository $deliveryReceiptRepo;

    protected function setUp(): void
    {
        $this->deliveryReceiptRepo = $this->createMock(DeliveryReceiptRepository::class);
        $this->service = new InvoiceMatchingService($this->deliveryReceiptRepo);
    }

    // =========================================================
    // RAPPROCHEMENT PAR NUMÉRO DE BL
    // =========================================================

    public function testMatchByBlNumberSucceeds(): void
    {
        $establishment = $this->makeEstablishment(1);
        $supplier      = $this->makeSupplier(1, 'TerreAzur');
        $bl            = $this->makeDeliveryReceipt('7830896247', $supplier, $establishment, '2025-08-26', '401.59');
        $invoice       = $this->makeInvoice('terra_7830896247', $supplier, $establishment, '2025-08-26', '401.59');
        $invoice->setRawData(['bl_number' => '7830896247']);

        $this->deliveryReceiptRepo
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['reference' => '7830896247', 'establishment' => $establishment])
            ->willReturn($bl);

        $result = $this->service->matchInvoiceWithDeliveryReceipt($invoice);

        $this->assertSame($bl, $result->deliveryReceipt);
        $this->assertSame('matched', $invoice->getStatus());
        $this->assertNotNull($invoice->getMatchedAt());
    }

    public function testMatchByBlNumberNotFound(): void
    {
        $establishment = $this->makeEstablishment(1);
        $supplier      = $this->makeSupplier(1, 'TerreAzur');
        $invoice       = $this->makeInvoice('terra_unknown', $supplier, $establishment, '2025-08-26', '401.59');
        $invoice->setRawData(['bl_number' => '9999999999']);

        $this->deliveryReceiptRepo
            ->method('findOneBy')
            ->willReturn(null);

        $this->deliveryReceiptRepo
            ->method('findCandidatesForMatching')
            ->willReturn([]);

        $result = $this->service->matchInvoiceWithDeliveryReceipt($invoice);

        $this->assertNull($result->deliveryReceipt);
        $this->assertSame('received', $invoice->getStatus());
        $this->assertNull($invoice->getMatchScore());
    }

    // =========================================================
    // RAPPROCHEMENT PAR FOURNISSEUR + DATE + MONTANT
    // =========================================================

    public function testMatchBySupplierDateAmountSucceeds(): void
    {
        $establishment = $this->makeEstablishment(1);
        $supplier      = $this->makeSupplier(1, 'TerreAzur');
        $bl            = $this->makeDeliveryReceipt('7830900916', $supplier, $establishment, '2025-09-05', '291.35');
        $invoice       = $this->makeInvoice('terra_7830900916', $supplier, $establishment, '2025-09-05', '291.35');
        $invoice->setRawData([]);   // Pas de numéro de BL dans les rawData

        $this->deliveryReceiptRepo
            ->method('findOneBy')
            ->willReturn(null);     // Pas de match direct par numéro

        $this->deliveryReceiptRepo
            ->method('findCandidatesForMatching')
            ->willReturn([$bl]);    // Un seul candidat dans la fenêtre ±7 jours

        $result = $this->service->matchInvoiceWithDeliveryReceipt($invoice);

        $this->assertSame($bl, $result->deliveryReceipt);
    }

    public function testOrphanInvoiceHasNoMatch(): void
    {
        $establishment = $this->makeEstablishment(1);
        $supplier      = $this->makeSupplier(1, 'TerreAzur');
        $invoice       = $this->makeInvoice('terra_7830925001', $supplier, $establishment, '2025-11-14', '214.72');
        $invoice->setRawData([]);

        $this->deliveryReceiptRepo->method('findOneBy')->willReturn(null);
        $this->deliveryReceiptRepo->method('findCandidatesForMatching')->willReturn([]);

        $result = $this->service->matchInvoiceWithDeliveryReceipt($invoice);

        $this->assertNull($result->deliveryReceipt);
        $this->assertSame('received', $invoice->getStatus());
        $this->assertArrayHasKey('match_attempts', $invoice->getMatchDetails());
    }

    // =========================================================
    // CALCUL DES ÉCARTS PRIX
    // =========================================================

    public function testPriceVarianceAboveThresholdIsDetected(): void
    {
        // Prosecco : BL 7,8320 → Facture 8,3200 (+6,23% > seuil 5%)
        $variance = $this->service->calculatePriceVariancePct('7.8320', '8.3200');

        $this->assertEqualsWithDelta(6.23, $variance, 0.01);
        $this->assertTrue($this->service->isPriceVarianceAboveThreshold($variance));
    }

    public function testPriceVarianceBelowThresholdIsAccepted(): void
    {
        // Variation de 3% → sous le seuil
        $variance = $this->service->calculatePriceVariancePct('10.0000', '10.3000');

        $this->assertEqualsWithDelta(3.0, $variance, 0.01);
        $this->assertFalse($this->service->isPriceVarianceAboveThreshold($variance));
    }

    public function testPriceVarianceExactlyAtThresholdIsAccepted(): void
    {
        // Exactement 5% → ne doit PAS déclencher d'alerte (seuil strict > 5%)
        $variance = $this->service->calculatePriceVariancePct('10.0000', '10.5000');

        $this->assertEqualsWithDelta(5.0, $variance, 0.01);
        $this->assertFalse($this->service->isPriceVarianceAboveThreshold($variance));
    }

    public function testPriceVarianceSlightlyAboveThresholdTriggersAlert(): void
    {
        // 5,01% → doit déclencher l'alerte
        $variance = $this->service->calculatePriceVariancePct('10.0000', '10.5010');

        $this->assertTrue($this->service->isPriceVarianceAboveThreshold($variance));
    }

    public function testNegativePriceVarianceRemise(): void
    {
        // Remise (prix facturé < prix BL) → variance négative, pas d'alerte hausse
        $variance = $this->service->calculatePriceVariancePct('9.3770', '9.3770');
        // Remise de -3,13 séparée sur une ligne dédiée → prix unitaire identique
        $this->assertEqualsWithDelta(0.0, $variance, 0.01);
        $this->assertFalse($this->service->isPriceVarianceAboveThreshold($variance));
    }

    // =========================================================
    // CALCUL DES ÉCARTS QUANTITÉ
    // =========================================================

    public function testQuantityVarianceIsDetected(): void
    {
        // 24 facturés vs 48 livrés → écart -24
        $variance = $this->service->calculateQuantityVariance('48.000', '24.000');

        $this->assertEqualsWithDelta(-24.0, $variance, 0.001);
    }

    public function testNoQuantityVarianceWhenEqual(): void
    {
        $variance = $this->service->calculateQuantityVariance('6.000', '6.000');

        $this->assertEqualsWithDelta(0.0, $variance, 0.001);
    }

    // =========================================================
    // DÉTECTION DES LIGNES ABSENTES DU BL
    // =========================================================

    public function testInvoiceLineAbsentFromBlIsDetected(): void
    {
        $establishment = $this->makeEstablishment(1);
        $supplier      = $this->makeSupplier(1, 'Le Bihan TMEG');
        $bl            = $this->makeDeliveryReceipt('00212540', $supplier, $establishment, '2025-09-18', '1243.18');

        // BL ne contient PAS le Cointreau
        $blLine = new DeliveryReceiptLine();
        $blLine->setDesignation('75CL PROSECCO PERLINO');
        $blLine->setQuantity('24.000');
        $blLine->setUnitPrice('7.8320');
        $bl->addLine($blLine);

        $invoice     = $this->makeInvoice('bihan_00212540', $supplier, $establishment, '2025-09-18', '1243.18');
        $invoiceLine = new SupplierInvoiceLine();
        $invoiceLine->setDesignation('70CL COINTREAU 40°');
        $invoiceLine->setQuantity('1.000');
        $invoiceLine->setUnitPrice('19.8500');
        $invoice->addLine($invoiceLine);

        $missingLines = $this->service->detectMissingLinesInBl($invoice, $bl);

        $this->assertCount(1, $missingLines);
        $this->assertStringContainsString('COINTREAU', $missingLines[0]['line']);
        $this->assertSame('invoice_only', $missingLines[0]['source']);
    }

    // =========================================================
    // CALCUL DU MATCH SCORE
    // =========================================================

    public function testPerfectMatchScoreIs100(): void
    {
        $score = $this->service->computeMatchScore(
            matchedLines: 13,
            totalLines: 13,
            priceGaps: [],
            qtyGaps: [],
            missingLines: []
        );

        $this->assertEqualsWithDelta(100.0, $score, 0.01);
    }

    public function testMatchScoreDecreasesWithGaps(): void
    {
        // 7 lignes OK sur 11, 2 écarts prix, 1 écart qté, 1 ligne absente
        $score = $this->service->computeMatchScore(
            matchedLines: 7,
            totalLines: 11,
            priceGaps: [
                ['variance_pct' => 6.23],
                ['variance_pct' => 8.53],
            ],
            qtyGaps: [['bl_qty' => 48, 'invoice_qty' => 24]],
            missingLines: [['line' => '70CL COINTREAU 40°']]
        );

        $this->assertLessThan(70.0, $score);
        $this->assertGreaterThan(0.0, $score);
    }

    public function testMatchScoreWithRemiseLineIsNotPenalized(): void
    {
        // Les lignes de remise (montant négatif, qté 0) ne doivent pas pénaliser le score
        $score = $this->service->computeMatchScore(
            matchedLines: 13,
            totalLines: 15,   // 2 lignes remise
            priceGaps: [],
            qtyGaps: [],
            missingLines: [],
            remiseLines: 2
        );

        $this->assertEqualsWithDelta(100.0, $score, 0.01);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function makeEstablishment(int $id): Establishment
    {
        $e = new Establishment();
        // Simulation d'un ID via réflexion (pas de setter public sur l'ID UUID)
        $ref = new \ReflectionProperty(Establishment::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($e, $id);
        return $e;
    }

    private function makeSupplier(int $id, string $name): Supplier
    {
        $s = new Supplier();
        $s->setName($name);
        $ref = new \ReflectionProperty(Supplier::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($s, $id);
        return $s;
    }

    private function makeDeliveryReceipt(
        string $reference,
        Supplier $supplier,
        Establishment $establishment,
        string $date,
        string $amountExclTax
    ): DeliveryReceipt {
        $bl = new DeliveryReceipt();
        $bl->setReference($reference);
        $bl->setSupplier($supplier);
        $bl->setEstablishment($establishment);
        $bl->setDeliveryDate(new \DateTimeImmutable($date));
        $bl->setAmountExclTax($amountExclTax);
        return $bl;
    }

    private function makeInvoice(
        string $externalId,
        Supplier $supplier,
        Establishment $establishment,
        string $issueDate,
        string $amountExclTax
    ): SupplierInvoice {
        $inv = new SupplierInvoice();
        $inv->setExternalId($externalId);
        $inv->setSupplier($supplier);
        $inv->setEstablishment($establishment);
        $inv->setIssueDate(new \DateTimeImmutable($issueDate));
        $inv->setAmountExclTax($amountExclTax);
        $inv->setStatus('received');
        return $inv;
    }
}
