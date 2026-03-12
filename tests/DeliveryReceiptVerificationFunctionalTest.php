<?php

declare(strict_types=1);

namespace App\Tests\Functional\DeliveryReceipt;

use App\DataFixtures\CatalogFixtures;
use App\DataFixtures\DeliveryReceiptFixtures;
use App\DataFixtures\EstablishmentFixtures;
use App\DataFixtures\SupplierFixtures;
use App\DataFixtures\UserFixtures;
use App\Entity\DeliveryReceipt;
use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests fonctionnels — Vérification BL vs mercuriale (MERC-2)
 *
 * Couvre :
 *   - Vérification d'un BL sans écart
 *   - Vérification d'un BL avec écart prix > 5%
 *   - Vérification d'un BL avec produit absent de la mercuriale
 *   - Seuil exact à 5% (pas d'alerte)
 *   - Lignes gratuites et remises (pas d'alerte)
 */
class DeliveryReceiptVerificationFunctionalTest extends WebTestCase
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
            CatalogFixtures::class,
            DeliveryReceiptFixtures::class,
        ]);
    }

    private function authHeader(): array
    {
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'admin@guinguette.fr', 'password' => 'password']));

        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE'       => 'application/json',
        ];
    }

    // =========================================================
    // BL CONFORME — AUCUNE ALERTE
    // =========================================================

    public function testVerifyCleanBlReturnsNoAlerts(): void
    {
        // BL TerreAzur 7830900916 — tous prix conformes à la mercuriale
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '7830900916']);

        $this->assertNotNull($bl, 'Fixture BL 7830900916 introuvable');

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEmpty($data['alerts']);
        $this->assertSame('verified', $data['status']);
    }

    // =========================================================
    // BL AVEC ÉCART PRIX > 5%
    // =========================================================

    public function testVerifyBlWithPriceGapReturnsAlert(): void
    {
        // BL Le Bihan 00212540 — Prosecco +6,23%, Grimbergen +8,53%
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '00212540']);

        $this->assertNotNull($bl, 'Fixture BL 00212540 introuvable');

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertNotEmpty($data['alerts']);
        $this->assertSame('pending_review', $data['status']);

        // Vérifier les deux alertes de prix
        $priceAlerts = array_filter($data['alerts'], fn($a) => $a['type'] === 'price_variance');
        $this->assertCount(2, array_values($priceAlerts));

        $designations = array_column(array_values($priceAlerts), 'designation');
        $this->assertContains('75CL PROSECCO PERLINO', $designations);
        $this->assertContains('FUT 20L GRIMBERGEN BLONDE', $designations);
    }

    public function testPriceAlertContainsExpectedFields(): void
    {
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '00212540']);

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        $data        = json_decode($this->client->getResponse()->getContent(), true);
        $priceAlerts = array_values(array_filter($data['alerts'], fn($a) => $a['type'] === 'price_variance'));

        $alert = $priceAlerts[0];
        $this->assertArrayHasKey('designation', $alert);
        $this->assertArrayHasKey('catalogPrice', $alert);
        $this->assertArrayHasKey('invoicedPrice', $alert);
        $this->assertArrayHasKey('variancePct', $alert);
        $this->assertGreaterThan(5.0, $alert['variancePct']);
    }

    // =========================================================
    // SEUIL EXACT À 5% — PAS D'ALERTE
    // =========================================================

    public function testVerifyLineAt5PercentThresholdProducesNoAlert(): void
    {
        // Créer à la volée une ligne de BL avec exactement 5% d'écart
        // via le endpoint de vérification simulée
        $this->client->request(
            'POST',
            '/api/price_check',
            [], [],
            $this->authHeader(),
            json_encode([
                'designation' => 'Oignon rge 60/80 5K c1 FR',
                'unitPrice'   => '3.4020',   // 3,2400 * 1.05 = exactement 5%
                'supplier'    => '/api/suppliers/1',
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertFalse($data['alert']);
        $this->assertEqualsWithDelta(5.0, $data['variancePct'], 0.01);
    }

    // =========================================================
    // LIGNES GRATUITES ET REMISES — PAS D'ALERTE
    // =========================================================

    public function testGratuitLinesDoNotTriggerPriceAlert(): void
    {
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '00210729']);

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Aucune alerte ne doit porter sur les lignes GRATUIT
        foreach ($data['alerts'] as $alert) {
            $this->assertStringNotContainsString('GRATUIT', $alert['designation'] ?? '');
            $this->assertStringNotContainsString('CARAIBOS', $alert['designation'] ?? '');
            $this->assertStringNotContainsString('VENEZZIO', $alert['designation'] ?? '');
        }
    }

    public function testRemiseLinesDoNotTriggerPriceAlert(): void
    {
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '00210729']);

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['alerts'] as $alert) {
            $this->assertStringNotContainsString('REMISE', $alert['designation'] ?? '');
        }
    }

    // =========================================================
    // PRODUIT ABSENT DE LA MERCURIALE
    // =========================================================

    public function testProductNotInCatalogGeneratesAlert(): void
    {
        // Créer un BL avec un produit absent de la mercuriale
        // via l'API d'upload (source: manual) et vérifier
        $this->client->request(
            'POST',
            '/api/price_check',
            [], [],
            $this->authHeader(),
            json_encode([
                'designation' => 'PRODUIT INEXISTANT DANS MERCURIALE XYZ',
                'unitPrice'   => '99.9900',
                'supplier'    => '/api/suppliers/1',
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['alert']);
        $this->assertSame('product_not_in_catalog', $data['alertType']);
    }

    // =========================================================
    // PRODUITS AVEC TVA MULTI-TAUX (Le Bihan TMEG)
    // =========================================================

    public function testVerifyBlWithMultipleVatRatesSucceeds(): void
    {
        // BL Le Bihan 00211162 contient des produits à 5,5%, 20% et taux accises
        $bl = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '00211162']);

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $bl->getId() . '/verify',
            [], [],
            $this->authHeader()
        );

        // La vérification doit fonctionner sans erreur malgré les taux multiples
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('alerts', $data);
        $this->assertArrayHasKey('status', $data);
    }

    // =========================================================
    // SÉCURITÉ — VÉRIFICATION D'UN BL D'UN AUTRE ÉTABLISSEMENT
    // =========================================================

    public function testCannotVerifyDeliveryReceiptOfAnotherEstablishment(): void
    {
        $blA = $this->em->getRepository(DeliveryReceipt::class)
            ->findOneBy(['reference' => '7830896247']);

        // Se connecter avec un utilisateur d'un autre établissement
        $this->client->request('POST', '/api/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'admin@autrerestau.fr', 'password' => 'password']));

        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        $this->client->request(
            'POST',
            '/api/delivery_receipts/' . $blA->getId() . '/verify',
            [], [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE'       => 'application/json',
            ]
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
