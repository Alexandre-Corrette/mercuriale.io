<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\DataFixtures\EstablishmentFixtures;
use App\DataFixtures\SupplierFixtures;
use App\DataFixtures\SupplierInvoiceFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\DeliveryReceipt;
use App\Entity\Establishment;
use App\Entity\Supplier;
use App\Entity\SupplierInvoice;
use App\Repository\SupplierInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels — Sécurité IDOR
 *
 * Vérifie qu'un utilisateur d'un établissement A ne peut pas
 * accéder aux ressources d'un établissement B, même en devinant
 * un UUID valide.
 *
 * Ressources testées :
 *   - SupplierInvoice
 *   - DeliveryReceipt
 *   - CatalogProduct (mercuriale)
 */
class IdorSecurityTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;

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
    }

    // =========================================================
    // HELPERS AUTH
    // =========================================================

    private function getTokenForEstablishment(string $establishment): string
    {
        // establishment_a = La Guinguette du Château
        // establishment_b = établissement tiers (autre client Mercuriale.io)
        $credentials = [
            'establishment_a' => ['email' => 'admin@guinguette.fr',    'password' => 'password'],
            'establishment_b' => ['email' => 'admin@autrerestau.fr',   'password' => 'password'],
            'cuisinier_a'     => ['email' => 'cuisinier@guinguette.fr', 'password' => 'password'],
        ];

        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($credentials[$establishment]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['token'];
    }

    private function authHeader(string $establishment): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->getTokenForEstablishment($establishment),
            'CONTENT_TYPE'       => 'application/json',
        ];
    }

    // =========================================================
    // IDOR — SUPPLIER INVOICE
    // =========================================================

    public function testCannotReadInvoiceOfAnotherEstablishment(): void
    {
        // Récupérer une facture appartenant à l'établissement A
        $invoiceA = $this->em->getRepository(SupplierInvoice::class)
            ->findOneBy(['externalId' => 'terra_7830896247']);

        $this->assertNotNull($invoiceA, 'Fixture invoice A introuvable');

        // Tenter d'y accéder avec le token de l'établissement B
        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $invoiceA->getId(),
            [], [],
            $this->authHeader('establishment_b')
        );

        $this->assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,   // 404 préféré au 403 pour ne pas confirmer l'existence
            'Un utilisateur d\'un autre établissement ne doit pas pouvoir accéder à cette facture'
        );
    }

    public function testCannotListInvoicesOfAnotherEstablishment(): void
    {
        $this->client->request('GET', '/api/supplier_invoices', [], [], $this->authHeader('establishment_b'));

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // L'établissement B ne doit voir AUCUNE facture de l'établissement A
        foreach ($data['hydra:member'] as $invoice) {
            $this->assertStringNotContainsString(
                'guinguette',
                strtolower($invoice['establishment'] ?? '')
            );
        }
    }

    public function testCannotPatchInvoiceOfAnotherEstablishment(): void
    {
        $invoiceA = $this->em->getRepository(SupplierInvoice::class)
            ->findOneBy(['externalId' => 'terra_7830896247']);

        $this->client->request(
            'PATCH',
            '/api/supplier_invoices/' . $invoiceA->getId(),
            [], [],
            array_merge(
                $this->authHeader('establishment_b'),
                ['CONTENT_TYPE' => 'application/merge-patch+json']
            ),
            json_encode(['status' => 'matched'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCannotTriggerMatchOnInvoiceOfAnotherEstablishment(): void
    {
        $invoiceA = $this->em->getRepository(SupplierInvoice::class)
            ->findOneBy(['externalId' => 'terra_7830896247']);

        $this->client->request(
            'POST',
            '/api/supplier_invoices/' . $invoiceA->getId() . '/match',
            [], [],
            $this->authHeader('establishment_b')
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // =========================================================
    // IDOR — DELIVERY RECEIPT
    // =========================================================

    public function testCannotReadDeliveryReceiptOfAnotherEstablishment(): void
    {
        $blA = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '7830896247']);

        $this->assertNotNull($blA, 'Fixture BL A introuvable');

        $this->client->request(
            'GET',
            '/api/delivery_receipts/' . $blA->getId(),
            [], [],
            $this->authHeader('establishment_b')
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCannotDeleteDeliveryReceiptOfAnotherEstablishment(): void
    {
        $blA = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '7830896247']);

        $this->client->request(
            'DELETE',
            '/api/delivery_receipts/' . $blA->getId(),
            [], [],
            $this->authHeader('establishment_b')
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // =========================================================
    // IDOR — ACCÈS NON AUTHENTIFIÉ
    // =========================================================

    public function testUnauthenticatedAccessToInvoiceIsRejected(): void
    {
        $invoiceA = $this->em->getRepository(SupplierInvoice::class)
            ->findOneBy(['externalId' => 'terra_7830896247']);

        $this->client->request('GET', '/api/supplier_invoices/' . $invoiceA->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnauthenticatedAccessToDeliveryReceiptIsRejected(): void
    {
        $blA = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '7830896247']);

        $this->client->request('GET', '/api/delivery_receipts/' . $blA->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // =========================================================
    // IDOR — UUID INVENTÉ (énumération)
    // =========================================================

    public function testRandomUuidReturns404NotLeakingInfo(): void
    {
        $fakeUuid = '00000000-0000-0000-0000-000000000000';

        $this->client->request(
            'GET',
            '/api/supplier_invoices/' . $fakeUuid,
            [], [],
            $this->authHeader('establishment_a')
        );

        // Doit retourner 404, pas 403 (ne pas confirmer l'existence de la ressource)
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // =========================================================
    // IDOR — MANIPULATION DU CHAMP establishment SUR CRÉATION
    // =========================================================

    public function testCannotCreateInvoiceForAnotherEstablishment(): void
    {
        // L'utilisateur de l'établissement A tente de créer une facture
        // en forçant l'IRI d'un autre établissement dans le payload
        $otherEstablishmentIri = '/api/establishments/99'; // établissement B

        $this->client->request(
            'POST',
            '/api/supplier_invoices',
            [], [],
            $this->authHeader('establishment_a'),
            json_encode([
                'establishment' => $otherEstablishmentIri,
                'supplier'      => '/api/suppliers/1',
                'reference'     => 'INJECT-TEST-001',
                'amountExclTax' => '100.00',
                'vatAmount'     => '5.50',
                'amountInclTax' => '105.50',
                'issueDate'     => '2025-11-01',
                'status'        => 'received',
                'source'        => 'manual',
            ])
        );

        // Soit 403 (Voter bloque), soit la facture est créée mais rattachée
        // à l'établissement de l'utilisateur (pas celui injecté)
        $statusCode = $this->client->getResponse()->getStatusCode();
        if ($statusCode === Response::HTTP_CREATED) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertStringNotContainsString(
                '/api/establishments/99',
                $data['establishment'],
                'L\'établissement doit être celui de l\'utilisateur, pas celui injecté'
            );
        } else {
            $this->assertContains($statusCode, [
                Response::HTTP_FORBIDDEN,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            ]);
        }
    }
}
