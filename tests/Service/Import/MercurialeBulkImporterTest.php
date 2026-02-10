<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\DTO\Import\ColumnMappingConfig;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\MercurialeImport;
use App\Entity\ProduitFournisseur;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Enum\StatutImport;
use App\Exception\Import\ImportException;
use App\Repository\MercurialeRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\ProduitRepository;
use App\Repository\UniteRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\MercurialeBulkImporter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MercurialeBulkImporterTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&ManagerRegistry $managerRegistry;
    private MockObject&ProduitFournisseurRepository $produitFournisseurRepository;
    private MockObject&ProduitRepository $produitRepository;
    private MockObject&MercurialeRepository $mercurialeRepository;
    private MockObject&UniteRepository $uniteRepository;
    private MockObject&ColumnMapper $columnMapper;
    private MockObject&LoggerInterface $logger;
    private MercurialeBulkImporter $importer;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->produitFournisseurRepository = $this->createMock(ProduitFournisseurRepository::class);
        $this->produitRepository = $this->createMock(ProduitRepository::class);
        $this->mercurialeRepository = $this->createMock(MercurialeRepository::class);
        $this->uniteRepository = $this->createMock(UniteRepository::class);
        $this->columnMapper = $this->createMock(ColumnMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->importer = new MercurialeBulkImporter(
            $this->entityManager,
            $this->managerRegistry,
            $this->produitFournisseurRepository,
            $this->produitRepository,
            $this->mercurialeRepository,
            $this->uniteRepository,
            $this->columnMapper,
            $this->logger,
        );
    }

    public function testPreviewCreatesNewProducts(): void
    {
        $import = $this->createMockImport([
            ['PROD001', 'Tomate', '2,50'],
            ['PROD002', 'Carotte', '1,80'],
        ]);

        // Products don't exist
        $this->produitFournisseurRepository->method('findOneBy')->willReturn(null);

        // Mapping returns valid data
        $this->columnMapper->method('mapRow')->willReturnCallback(function ($row) {
            return [
                'code_fournisseur' => $row[0],
                'designation' => $row[1],
                'prix' => str_replace(',', '.', $row[2]) . '00',
                'unite' => 'KG',
                'conditionnement' => null,
                'date_debut' => null,
                'date_fin' => null,
            ];
        });

        $this->columnMapper->method('validateMappedRow')->willReturn(['valid' => true, 'errors' => []]);
        $this->columnMapper->method('resolveUnite')->willReturn($this->createMock(Unite::class));

        $this->entityManager->method('flush');

        $preview = $this->importer->preview($import);

        $this->assertEquals(2, $preview->totalRows);
        $this->assertEquals(2, $preview->createCount);
        $this->assertEquals(0, $preview->updateCount);
        $this->assertEquals(0, $preview->errorRows);
        $this->assertTrue($preview->canProceed());
    }

    public function testPreviewDetectsExistingProducts(): void
    {
        $import = $this->createMockImport([
            ['PROD001', 'Tomate', '2,50'],
        ]);

        // Product exists
        $existingProduct = $this->createMock(ProduitFournisseur::class);
        $existingProduct->method('getId')->willReturn(1);
        $this->produitFournisseurRepository->method('findOneBy')->willReturn($existingProduct);

        // No existing mercuriale
        $this->mercurialeRepository->method('findPrixValide')->willReturn(null);

        $this->columnMapper->method('mapRow')->willReturn([
            'code_fournisseur' => 'PROD001',
            'designation' => 'Tomate',
            'prix' => '2.5000',
            'unite' => 'KG',
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ]);
        $this->columnMapper->method('validateMappedRow')->willReturn(['valid' => true, 'errors' => []]);
        $this->columnMapper->method('resolveUnite')->willReturn($this->createMock(Unite::class));

        $this->entityManager->method('flush');

        $preview = $this->importer->preview($import);

        $this->assertEquals(1, $preview->totalRows);
        $this->assertEquals(0, $preview->createCount);
        $this->assertEquals(1, $preview->updateCount);
    }

    public function testPreviewDetectsErrors(): void
    {
        $import = $this->createMockImport([
            ['', 'Tomate', '2,50'], // Missing code
        ]);

        $this->columnMapper->method('mapRow')->willReturn([
            'code_fournisseur' => '',
            'designation' => 'Tomate',
            'prix' => '2.5000',
            'unite' => null,
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ]);

        $this->columnMapper->method('validateMappedRow')->willReturn([
            'valid' => false,
            'errors' => [['field' => 'code_fournisseur', 'message' => 'Code fournisseur manquant']],
        ]);

        $this->entityManager->method('flush');

        $preview = $this->importer->preview($import);

        $this->assertEquals(1, $preview->totalRows);
        $this->assertEquals(1, $preview->errorRows);
        $this->assertFalse($preview->canProceed());
    }

    public function testPreviewExpiredImportThrowsException(): void
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $user = $this->createMock(Utilisateur::class);

        $import = $this->createMock(MercurialeImport::class);
        $import->method('canBeProcessed')->willReturn(false);
        $import->method('getFournisseur')->willReturn($fournisseur);
        $import->method('getCreatedBy')->willReturn($user);

        $this->expectException(ImportException::class);
        $this->importer->preview($import);
    }

    public function testPreviewMissingMappingThrowsException(): void
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $user = $this->createMock(Utilisateur::class);

        $import = $this->createMock(MercurialeImport::class);
        $import->method('canBeProcessed')->willReturn(true);
        $import->method('getColumnMapping')->willReturn(null);
        $import->method('getFournisseur')->willReturn($fournisseur);
        $import->method('getCreatedBy')->willReturn($user);

        $this->expectException(ImportException::class);
        $this->importer->preview($import);
    }

    public function testExecuteRequiresPreviewed(): void
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $user = $this->createMock(Utilisateur::class);

        $import = $this->createMock(MercurialeImport::class);
        $import->method('canBeProcessed')->willReturn(true);
        $import->method('getStatus')->willReturn(StatutImport::MAPPING);
        $import->method('getColumnMapping')->willReturn(['mapping' => []]);
        $import->method('getFournisseur')->willReturn($fournisseur);
        $import->method('getCreatedBy')->willReturn($user);

        $this->expectException(ImportException::class);
        $this->importer->execute($import, $user);
    }

    private function createMockImport(array $rows): MockObject&MercurialeImport
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $fournisseur->method('getId')->willReturn(1);

        $user = $this->createMock(Utilisateur::class);

        $import = $this->createMock(MercurialeImport::class);
        $import->method('canBeProcessed')->willReturn(true);
        $import->method('getFournisseur')->willReturn($fournisseur);
        $import->method('getEtablissements')->willReturn(new ArrayCollection());
        $import->method('getCreatedBy')->willReturn($user);
        $import->method('getParsedData')->willReturn([
            'headers' => ['Code', 'Designation', 'Prix'],
            'rows' => $rows,
            'totalRows' => \count($rows),
        ]);
        $import->method('getColumnMapping')->willReturn([
            'mapping' => [
                0 => 'code_fournisseur',
                1 => 'designation',
                2 => 'prix',
            ],
            'hasHeaderRow' => true,
            'defaultUnite' => null,
            'defaultDateDebut' => null,
        ]);

        return $import;
    }
}
