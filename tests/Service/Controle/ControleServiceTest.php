<?php

declare(strict_types=1);

namespace App\Tests\Service\Controle;

use App\Entity\AlerteControle;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\LigneBonLivraison;
use App\Entity\Mercuriale;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Enum\StatutBonLivraison;
use App\Enum\StatutControle;
use App\Enum\TypeAlerte;
use App\Repository\MercurialeRepository;
use App\Service\Controle\ControleService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ControleServiceTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&MercurialeRepository $mercurialeRepository;
    private MockObject&LoggerInterface $logger;
    private ControleService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mercurialeRepository = $this->createMock(MercurialeRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ControleService(
            $this->entityManager,
            $this->mercurialeRepository,
            $this->logger,
        );
    }

    public function testControlerQuantiteEcartDetecte(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '10.000',
            quantiteLivree: '8.000',
            prixUnitaire: '2.50',
        );

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        // Pas de mercuriale pour ce produit
        $this->mercurialeRepository->method('findPrixValide')->willReturn(null);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        // 2 alertes: écart quantité + prix manquant
        $this->assertEquals(2, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $alertes = $ligne->getAlertes();

        $hasQuantiteAlerte = false;
        foreach ($alertes as $alerte) {
            if ($alerte->getTypeAlerte() === TypeAlerte::ECART_QUANTITE) {
                $hasQuantiteAlerte = true;
                $this->assertEquals('10.000', $alerte->getValeurAttendue());
                $this->assertEquals('8.000', $alerte->getValeurRecue());
                $this->assertStringContainsString('-20', $alerte->getEcartPct());
            }
        }

        $this->assertTrue($hasQuantiteAlerte, 'Alerte écart quantité non trouvée');
    }

    public function testControlerQuantiteSansEcart(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '3.00',
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Mercuriale avec prix exact
        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(0, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $this->assertEquals(StatutControle::OK, $ligne->getStatutControle());
    }

    public function testControlerPrixEcartAuDessusSeuil(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '3.50', // Prix BL
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Prix mercuriale : 3.00 €, seuil : 5%
        // Écart : (3.50 - 3.00) / 3.00 = 16.67% > 5%
        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(1, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $alertes = $ligne->getAlertes();

        $this->assertCount(1, $alertes);
        $alerte = $alertes->first();
        $this->assertEquals(TypeAlerte::ECART_PRIX, $alerte->getTypeAlerte());
        $this->assertStringContainsString('supérieur', $alerte->getMessage());
    }

    public function testControlerPrixEcartEnDessousSeuil(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '3.10', // Prix BL
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Prix mercuriale : 3.00 €, seuil : 5%
        // Écart : (3.10 - 3.00) / 3.00 = 3.33% < 5%
        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(0, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $this->assertEquals(StatutControle::OK, $ligne->getStatutControle());
    }

    public function testControlerPrixMercurialePrioriteEtablissement(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '2.80',
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Prix établissement : 2.80 € (exact match)
        $mercurialeEtablissement = $this->createMercuriale(2.80, 5.0);

        // Prix groupe : 3.00 € (écart)
        $mercurialeGroupe = $this->createMercuriale(3.00, 5.0);

        $this->mercurialeRepository
            ->method('findPrixValide')
            ->willReturnCallback(function ($pf, $etablissement, $date) use ($mercurialeEtablissement, $mercurialeGroupe) {
                // Priorité établissement
                if ($etablissement !== null) {
                    return $mercurialeEtablissement;
                }

                return $mercurialeGroupe;
            });

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        // Pas d'alerte car le prix établissement (2.80) match le prix BL (2.80)
        $this->assertEquals(0, $nombreAlertes);
    }

    public function testControlerProduitInconnu(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '3.00',
            withProduitFournisseur: false,
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(1, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $alertes = $ligne->getAlertes();

        $this->assertCount(1, $alertes);
        $alerte = $alertes->first();
        $this->assertEquals(TypeAlerte::PRODUIT_INCONNU, $alerte->getTypeAlerte());
        $this->assertStringContainsString('non référencé', $alerte->getMessage());
    }

    public function testControlerPrixManquant(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '5.000',
            quantiteLivree: '5.000',
            prixUnitaire: '3.00',
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        // Pas de mercuriale
        $this->mercurialeRepository->method('findPrixValide')->willReturn(null);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(1, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $alertes = $ligne->getAlertes();

        $this->assertCount(1, $alertes);
        $alerte = $alertes->first();
        $this->assertEquals(TypeAlerte::PRIX_MANQUANT, $alerte->getTypeAlerte());
    }

    public function testControlerEcartsMultiples(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '10.000',
            quantiteLivree: '8.000', // Écart quantité
            prixUnitaire: '4.00', // Écart prix (attendu 3.00)
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        $this->assertEquals(2, $nombreAlertes);

        $ligne = $bl->getLignes()->first();
        $this->assertEquals(StatutControle::ECART_MULTIPLE, $ligne->getStatutControle());
    }

    public function testControlerSansQuantiteCommandee(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: null, // Pas de commande
            quantiteLivree: '5.000',
            prixUnitaire: '3.00',
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $nombreAlertes = $this->service->controlerBonLivraison($bl);

        // Pas d'alerte quantité car pas de quantité commandée
        $this->assertEquals(0, $nombreAlertes);
    }

    public function testStatutBLAnomalieSiAlertes(): void
    {
        $bl = $this->createBonLivraisonWithLigne(
            quantiteCommandee: '10.000',
            quantiteLivree: '5.000', // Écart important
            prixUnitaire: '3.00',
        );

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $mercuriale = $this->createMercuriale(3.00, 5.0);
        $this->mercurialeRepository->method('findPrixValide')->willReturn($mercuriale);

        $this->service->controlerBonLivraison($bl);

        $this->assertEquals(StatutBonLivraison::ANOMALIE, $bl->getStatut());
    }

    private function createBonLivraisonWithLigne(
        ?string $quantiteCommandee,
        string $quantiteLivree,
        string $prixUnitaire,
        bool $withProduitFournisseur = true,
    ): BonLivraison {
        $etablissement = $this->createMock(Etablissement::class);
        $etablissement->method('getId')->willReturn(1);

        $fournisseur = $this->createMock(Fournisseur::class);
        $fournisseur->method('getId')->willReturn(1);

        $unite = $this->createMock(Unite::class);
        $unite->method('getCode')->willReturn('kg');

        $bl = new BonLivraison();
        $bl->setEtablissement($etablissement);
        $bl->setFournisseur($fournisseur);
        $bl->setDateLivraison(new \DateTimeImmutable('2026-01-31'));
        $bl->setStatut(StatutBonLivraison::BROUILLON);

        $ligne = new LigneBonLivraison();
        $ligne->setBonLivraison($bl);
        $ligne->setDesignationBl('Produit Test');
        $ligne->setCodeProduitBl('TEST-001');
        $ligne->setQuantiteCommandee($quantiteCommandee);
        $ligne->setQuantiteLivree($quantiteLivree);
        $ligne->setPrixUnitaire($prixUnitaire);
        $ligne->setTotalLigne(bcmul($quantiteLivree, $prixUnitaire, 4));
        $ligne->setUnite($unite);

        if ($withProduitFournisseur) {
            $produitFournisseur = $this->createMock(ProduitFournisseur::class);
            $produitFournisseur->method('getDesignationFournisseur')->willReturn('Produit Test');
            $ligne->setProduitFournisseur($produitFournisseur);
        }

        $bl->addLigne($ligne);

        return $bl;
    }

    private function createMercuriale(float $prixNegocie, float $seuilAlertePct): Mercuriale
    {
        $mercuriale = $this->createMock(Mercuriale::class);
        $mercuriale->method('getPrixNegocieAsFloat')->willReturn($prixNegocie);
        $mercuriale->method('getSeuilAlertePctAsFloat')->willReturn($seuilAlertePct);

        return $mercuriale;
    }
}
