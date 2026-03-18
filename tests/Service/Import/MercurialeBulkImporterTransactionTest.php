<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\DTO\Import\ColumnMappingConfig;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\MercurialeImport;
use App\Entity\Unite;
use App\Entity\Utilisateur;
use App\Enum\StatutImport;
use App\Repository\MercurialeRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\ProduitRepository;
use App\Repository\UniteRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\MercurialeBulkImporter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests that MercurialeBulkImporter handles transactions correctly:
 * - Valid rows are persisted, invalid rows are excluded
 * - No partial entities leak into the UnitOfWork
 * - All-invalid imports persist nothing
 */
class MercurialeBulkImporterTransactionTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&ColumnMapper $columnMapper;
    private MockObject&UniteRepository $uniteRepository;
    private MockObject&ProduitFournisseurRepository $produitFournisseurRepository;
    private MockObject&ProduitRepository $produitRepository;
    private MockObject&MercurialeRepository $mercurialeRepository;
    private MockObject&Connection $connection;
    private MercurialeBulkImporter $importer;

    /** @var object[] Entities that were passed to persist() */
    private array $persistedEntities = [];

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->columnMapper = $this->createMock(ColumnMapper::class);
        $this->uniteRepository = $this->createMock(UniteRepository::class);
        $this->produitFournisseurRepository = $this->createMock(ProduitFournisseurRepository::class);
        $this->produitRepository = $this->createMock(ProduitRepository::class);
        $this->mercurialeRepository = $this->createMock(MercurialeRepository::class);
        $this->connection = $this->createMock(Connection::class);
        $managerRegistry = $this->createMock(ManagerRegistry::class);

        $this->entityManager->method('getConnection')->willReturn($this->connection);
        $this->entityManager->method('isOpen')->willReturn(true);
        $this->connection->method('isTransactionActive')->willReturn(true);

        // Track all persist() calls
        $this->persistedEntities = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity): void {
                $this->persistedEntities[] = $entity;
            });

        $this->importer = new MercurialeBulkImporter(
            $this->entityManager,
            $managerRegistry,
            $this->produitFournisseurRepository,
            $this->produitRepository,
            $this->mercurialeRepository,
            $this->uniteRepository,
            $this->columnMapper,
            new NullLogger(),
        );
    }

    public function testValidAndInvalidRowsPersistsOnlyValidEntities(): void
    {
        // 3 valid rows + 2 invalid rows
        $rows = [
            ['code' => 'P001', 'designation' => 'Produit 1', 'prix' => '10.50', 'unite' => 'kg'],
            ['code' => 'P002', 'designation' => 'Produit 2', 'prix' => '20.00', 'unite' => 'L'],
            ['code' => '', 'designation' => '', 'prix' => '', 'unite' => ''],      // invalid: empty
            ['code' => 'P003', 'designation' => 'Produit 3', 'prix' => '5.00', 'unite' => 'kg'],
            ['code' => '', 'designation' => '', 'prix' => 'abc', 'unite' => ''],   // invalid: bad price
        ];

        $import = $this->createImportWithRows($rows);

        $this->configureColumnMapper($rows);
        $this->configureDefaultUnit();

        // Rows 0,1,3 are valid; Rows 2,4 are invalid
        $this->columnMapper->method('validateMappedRow')
            ->willReturnCallback(function (array $data): array {
                if (empty($data['designation'])) {
                    return ['valid' => false, 'errors' => [['field' => 'designation', 'message' => 'Designation obligatoire']]];
                }

                return ['valid' => true, 'errors' => [], 'warnings' => []];
            });

        $this->produitFournisseurRepository->method('findOneBy')->willReturn(null);
        $this->produitRepository->method('findOneBy')->willReturn(null);

        $result = $this->importer->execute($import, $this->createUser());

        self::assertSame(2, $result->failed, 'Should report 2 failed rows');
        self::assertSame(3, $result->productsCreated, 'Should create 3 products');
        // Each valid row creates: 1 ProduitFournisseur + 1 Produit + 1 Mercuriale = 3 entities
        // 3 valid rows × 3 entities = 9 persisted entities
        self::assertCount(9, $this->persistedEntities, 'Should persist exactly 9 entities (3 rows × 3 entities)');
    }

    public function testAllInvalidRowsPersistsNothing(): void
    {
        $rows = [
            ['code' => '', 'designation' => '', 'prix' => '', 'unite' => ''],
            ['code' => '', 'designation' => '', 'prix' => 'abc', 'unite' => ''],
        ];

        $import = $this->createImportWithRows($rows);

        $this->configureColumnMapper($rows);
        $this->configureDefaultUnit();

        $this->columnMapper->method('validateMappedRow')
            ->willReturn(['valid' => false, 'errors' => [['field' => 'designation', 'message' => 'Obligatoire']]]);

        // beginTransaction should still be called but commit should have 0 entities
        $this->entityManager->expects(self::once())->method('beginTransaction');
        // flush + commit are called for the (empty) valid set, then flush for status update
        $this->entityManager->expects(self::exactly(2))->method('flush');
        $this->entityManager->expects(self::once())->method('commit');

        $result = $this->importer->execute($import, $this->createUser());

        self::assertSame(2, $result->failed);
        self::assertSame(0, $result->productsCreated);
        self::assertSame(0, $result->productsUpdated);
        self::assertCount(0, $this->persistedEntities, 'Should persist 0 entities');
    }

    public function testAllValidRowsPersistsAll(): void
    {
        $rows = [
            ['code' => 'P001', 'designation' => 'Produit 1', 'prix' => '10.50', 'unite' => 'kg'],
            ['code' => 'P002', 'designation' => 'Produit 2', 'prix' => '20.00', 'unite' => 'L'],
            ['code' => 'P003', 'designation' => 'Produit 3', 'prix' => '5.00', 'unite' => 'kg'],
        ];

        $import = $this->createImportWithRows($rows);

        $this->configureColumnMapper($rows);
        $this->configureDefaultUnit();

        $this->columnMapper->method('validateMappedRow')
            ->willReturn(['valid' => true, 'errors' => [], 'warnings' => []]);

        $this->produitFournisseurRepository->method('findOneBy')->willReturn(null);
        $this->produitRepository->method('findOneBy')->willReturn(null);

        $result = $this->importer->execute($import, $this->createUser());

        self::assertSame(0, $result->failed);
        self::assertSame(3, $result->productsCreated);
        // 3 rows × 3 entities (ProduitFournisseur + Produit + Mercuriale)
        self::assertCount(9, $this->persistedEntities, 'Should persist all 9 entities');
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function createImportWithRows(array $rows): MercurialeImport
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $etablissement = $this->createMock(Etablissement::class);

        $import = $this->createMock(MercurialeImport::class);
        $import->method('canBeProcessed')->willReturn(true);
        $import->method('getStatus')->willReturn(StatutImport::PREVIEWED);
        $import->method('getColumnMapping')->willReturn([
            'code_fournisseur' => 0,
            'designation' => 1,
            'prix' => 2,
            'unite' => 3,
        ]);
        $import->method('getParsedData')->willReturn(['rows' => $rows]);
        $import->method('getFournisseur')->willReturn($fournisseur);
        $import->method('getEtablissements')->willReturn(new ArrayCollection([$etablissement]));
        $import->method('getIdAsString')->willReturn('test-import-id');

        return $import;
    }

    private function createUser(): Utilisateur
    {
        $user = $this->createMock(Utilisateur::class);

        return $user;
    }

    private function configureColumnMapper(array $rows): void
    {
        $this->columnMapper->method('mapRow')
            ->willReturnCallback(function (array $row) {
                return [
                    'code_fournisseur' => $row['code'] ?? '',
                    'designation' => $row['designation'] ?? '',
                    'prix' => $row['prix'] ?? '',
                    'unite' => $row['unite'] ?? '',
                    'conditionnement' => null,
                    'date_debut' => null,
                    'date_fin' => null,
                ];
            });

        $this->columnMapper->method('resolveUnite')
            ->willReturn($this->createMock(Unite::class));
    }

    private function configureDefaultUnit(): void
    {
        $this->uniteRepository->method('findOneBy')
            ->willReturn($this->createMock(Unite::class));
    }
}
