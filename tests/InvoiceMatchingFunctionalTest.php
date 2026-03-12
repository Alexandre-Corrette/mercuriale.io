<?php

declare(strict_types=1);

namespace App\Tests\Functional\Invoice;

use App\DataFixtures\EstablishmentFixtures;
use App\DataFixtures\SupplierFixtures;
use App\DataFixtures\SupplierInvoiceFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\SupplierInvoice;
use App\Repository\SupplierInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels — Rapprochement facture ↔ BL
 *
 * Couvre les endpoints API Platform :
 *   GET    /api/supplier_invoices
 *   GET    /api/supplier_invoices/{id}
 *   POST   /api/supplier_invoices/{id}/match
 *   PATCH  /api/supplier_invoices/{id}  (transition statut)
 */
class InvoiceMatchingFunctionalTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private SupplierInvoiceRepository $invoiceRepo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $databaseTool = static::getContainer()->get(DatabaseToolCollection::class)->get();
        $databaseTool->loadFixtures([
            EstablishmentFixtures::class,
            SupplierFixtures::class,
            UserFixtures::class,
            SupplierInvoiceFixtures::class,
        ]);

        $this->invoiceRepo = $this->em->getRepository(SupplierInvoice::class);
    }

    // =========================================================
    // AUTHENTIFICATION
    // =========================================================

    private function getAuthHeader(string $role = 'admin'): array
    {
        // Obtenir un JWT valide pour les tests
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => $role === 'admin' ? 'admin@guinguette.fr' : 'cuisinier@guinguette.fr',
            'password' => 'password',
        ]));

        $data  = json_decode($this->client->getResponse()->getContent(), true);
        $token = $data['token'];

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'application/json',
        ];
    }

    // =========================================================
    // LISTE DES FACTURES
    // =========================================================

    public function testGetInvoicesReturnsOnlyOwnEstablishment(): void
    {
        $this->client->request('GET', '/api/supplier_invoices', [], [], $this->getAuthHeader());

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Toutes les factures retournées appartiennent à l'établissement de l'utilisateur
        foreach ($data['hydra:member'] as $invoice) {
            $this->assertStringContainsString('/api/establishments/1', $invoice['establishment']);
        }
    }

    public function testGetInvoicesFilterByStatus(): void
    {
        $this->client->request(
            'GET',
            '/api/supplier_invoices?status=pending_review',
            [], [],
            $this->getAuthHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['hydra:member'] as $invoice) {
            $this->assertSame('pending_review', $invoice['status']);
        }
    }

    public function testGetInvoicesFilterBySupplier(): void
    {
        $this->client->request(
            'GET',
            '/api/supplier_invoices?supplier.name=TerreAzur',
            [], [],
            $this->getAuthHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertGreaterThanOrEqual(3, $data['hydra:totalItems']);
    }

    // =========================================================
    // FACTURE ORPHELINE (sans BL)
    // =========================================================

    public function testOrphanInvoiceHasNullMatchScore(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'terra_7830925001']);
        $this->assertNotNull($invoice);

        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $invoice->getId(),
            [], [],
            $this->getAuthHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertNull($data['matchScore']);
        $this->assertSame('received', $data['status']);
        $this->assertNull($data['deliveryReceipt'] ?? null);
    }

    public function testTriggerMatchingOnOrphanInvoice(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'terra_7830925001']);

        $this->client->request(
            'POST',
            '/api/supplier_invoices/' . $invoice->getId() . '/match',
            [], [],
            $this->getAuthHeader()
        );

        // Pas de BL candidat → 200 OK mais status toujours "received"
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('received', $data['status']);
        $this->assertArrayHasKey('matchAttempts', $data);
    }

    // =========================================================
    // FACTURE AVEC ÉCARTS (FAC-BIHAN-2025-09-2540)
    // =========================================================

    public function testInvoiceWithPriceGapsHasPendingReviewStatus(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'bihan_00212540']);
        $this->assertNotNull($invoice);

        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $invoice->getId(),
            [], [],
            $this->getAuthHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame('pending_review', $data['status']);
        $this->assertNotEmpty($data['matchDetails']['price_gaps']);
        $this->assertCount(2, $data['matchDetails']['price_gaps']);
    }

    public function testInvoiceWithGapsHasLowMatchScore(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'bihan_00212540']);

        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $invoice->getId(),
            [], [],
            $this->getAuthHeader()
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertLessThan(70, (float) $data['matchScore']);
    }

    public function testInvoiceLineAbsentFromBlIsInMatchDetails(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'bihan_00212540']);

        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $invoice->getId(),
            [], [],
            $this->getAuthHeader()
        );

        $data         = json_decode($this->client->getResponse()->getContent(), true);
        $missingLines = $data['matchDetails']['missing_lines'] ?? [];

        $this->assertNotEmpty($missingLines);
        $this->assertSame('invoice_only', $missingLines[0]['source']);
        $this->assertStringContainsString('COINTREAU', $missingLines[0]['line']);
    }

    // =========================================================
    // TRANSITION DE STATUT
    // =========================================================

    public function testAdminCanValidateMatchedInvoice(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'terra_7830896247']);

        $this->client->request(
            'POST',
            '/api/supplier_invoices/' . $invoice->getId() . '/validate',
            [], [],
            $this->getAuthHeader('admin')
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('validated', $data['status']);
    }

    public function testCuisinierCannotValidateInvoice(): void
    {
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'terra_7830896247']);

        $this->client->request(
            'POST',
            '/api/supplier_invoices/' . $invoice->getId() . '/validate',
            [], [],
            $this->getAuthHeader('cuisinier')
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCannotTransitionFromReceivedToMatched(): void
    {
        // Une facture "received" sans BL ne peut pas passer directement à "matched"
        $invoice = $this->invoiceRepo->findOneBy(['externalId' => 'terra_7830925001']);

        $this->client->request(
            'PATCH',
            '/api/supplier_invoices/' . $invoice->getId(),
            [], [],
            array_merge($this->getAuthHeader('admin'), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            json_encode(['status' => 'matched'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
