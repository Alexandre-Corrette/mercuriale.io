<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\DTO\Import\ColumnMappingConfig;
use App\Entity\Unite;
use App\Repository\UniteRepository;
use App\Service\Import\ColumnMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ColumnMapperTest extends TestCase
{
    private MockObject&UniteRepository $uniteRepository;
    private MockObject&LoggerInterface $logger;
    private ColumnMapper $mapper;

    protected function setUp(): void
    {
        $this->uniteRepository = $this->createMock(UniteRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Setup mock units
        $kgUnit = $this->createMock(Unite::class);
        $kgUnit->method('getCode')->willReturn('KG');

        $pcUnit = $this->createMock(Unite::class);
        $pcUnit->method('getCode')->willReturn('PC');

        $this->uniteRepository->method('findAll')->willReturn([$kgUnit, $pcUnit]);

        $this->mapper = new ColumnMapper(
            $this->uniteRepository,
            $this->logger,
        );
    }

    public function testNormalizePriceFrenchFormat(): void
    {
        $this->assertEquals('2.5000', $this->mapper->normalizePrice('2,50'));
        $this->assertEquals('1234.5600', $this->mapper->normalizePrice('1 234,56'));
        $this->assertEquals('1234.5600', $this->mapper->normalizePrice('1234,56'));
    }

    public function testNormalizePriceEnglishFormat(): void
    {
        $this->assertEquals('2.5000', $this->mapper->normalizePrice('2.50'));
        $this->assertEquals('1234.5600', $this->mapper->normalizePrice('1234.56'));
    }

    public function testNormalizePriceWithCurrency(): void
    {
        $this->assertEquals('2.5000', $this->mapper->normalizePrice('2,50 EUR'));
        $this->assertEquals('2.5000', $this->mapper->normalizePrice('2.50$'));
        $this->assertEquals('2.5000', $this->mapper->normalizePrice('£2.50'));
    }

    public function testNormalizePriceInvalidReturnsNull(): void
    {
        $this->assertNull($this->mapper->normalizePrice('abc'));
        $this->assertNull($this->mapper->normalizePrice(''));
        $this->assertNull($this->mapper->normalizePrice('N/A'));
    }

    public function testNormalizeUniteKnownValues(): void
    {
        $this->assertEquals('KG', $this->mapper->normalizeUnite('kg'));
        $this->assertEquals('KG', $this->mapper->normalizeUnite('kilo'));
        $this->assertEquals('KG', $this->mapper->normalizeUnite('kilogramme'));

        $this->assertEquals('PC', $this->mapper->normalizeUnite('pc'));
        $this->assertEquals('PC', $this->mapper->normalizeUnite('piece'));
        $this->assertEquals('PC', $this->mapper->normalizeUnite('unite'));

        $this->assertEquals('L', $this->mapper->normalizeUnite('l'));
        $this->assertEquals('L', $this->mapper->normalizeUnite('litre'));
    }

    public function testNormalizeUniteUnknownUppercased(): void
    {
        $this->assertEquals('UNKNOWN', $this->mapper->normalizeUnite('unknown'));
        $this->assertEquals('XYZ', $this->mapper->normalizeUnite('xyz'));
    }

    public function testNormalizeDateFrenchFormat(): void
    {
        $this->assertEquals('2024-03-15', $this->mapper->normalizeDate('15/03/2024'));
        $this->assertEquals('2024-03-15', $this->mapper->normalizeDate('15-03-2024'));
    }

    public function testNormalizeDateIsoFormat(): void
    {
        $this->assertEquals('2024-03-15', $this->mapper->normalizeDate('2024-03-15'));
        $this->assertEquals('2024-03-15', $this->mapper->normalizeDate('2024/03/15'));
    }

    public function testNormalizeDateInvalidReturnsNull(): void
    {
        $this->assertNull($this->mapper->normalizeDate('invalid'));
        $this->assertNull($this->mapper->normalizeDate(''));
        $this->assertNull($this->mapper->normalizeDate('32/13/2024'));
    }

    public function testNormalizeQuantity(): void
    {
        $this->assertEquals('1.000', $this->mapper->normalizeQuantity('1'));
        $this->assertEquals('1.500', $this->mapper->normalizeQuantity('1,5'));
        $this->assertEquals('1.500', $this->mapper->normalizeQuantity('1.5'));
        $this->assertEquals('10.000', $this->mapper->normalizeQuantity('10'));
    }

    public function testMapRowBasic(): void
    {
        $config = new ColumnMappingConfig(
            mapping: [
                0 => 'code_fournisseur',
                1 => 'designation',
                2 => 'prix',
            ],
            hasHeaderRow: true,
        );

        $row = ['PROD001', 'Tomate', '2,50'];

        $result = $this->mapper->mapRow($row, $config);

        $this->assertEquals('PROD001', $result['code_fournisseur']);
        $this->assertEquals('Tomate', $result['designation']);
        $this->assertEquals('2.5000', $result['prix']);
    }

    public function testMapRowWithDefaults(): void
    {
        $config = new ColumnMappingConfig(
            mapping: [
                0 => 'code_fournisseur',
                1 => 'designation',
                2 => 'prix',
            ],
            hasHeaderRow: true,
            defaultUnite: 'KG',
            defaultDateDebut: new \DateTimeImmutable('2024-01-01'),
        );

        $row = ['PROD001', 'Tomate', '2,50'];

        $result = $this->mapper->mapRow($row, $config);

        $this->assertEquals('KG', $result['unite']);
        $this->assertEquals('2024-01-01', $result['date_debut']);
    }

    public function testMapRowIgnoredColumns(): void
    {
        $config = new ColumnMappingConfig(
            mapping: [
                0 => 'code_fournisseur',
                1 => ColumnMappingConfig::FIELD_IGNORE,
                2 => 'designation',
                3 => 'prix',
            ],
            hasHeaderRow: true,
        );

        $row = ['PROD001', 'ignored data', 'Tomate', '2,50'];

        $result = $this->mapper->mapRow($row, $config);

        $this->assertEquals('PROD001', $result['code_fournisseur']);
        $this->assertEquals('Tomate', $result['designation']);
        $this->assertEquals('2.5000', $result['prix']);
    }

    public function testValidateMappedRowValid(): void
    {
        $data = [
            'code_fournisseur' => 'PROD001',
            'designation' => 'Tomate',
            'prix' => '2.5000',
            'unite' => 'KG',
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ];

        $result = $this->mapper->validateMappedRow($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateMappedRowMissingRequired(): void
    {
        $data = [
            'code_fournisseur' => '',
            'designation' => 'Tomate',
            'prix' => '2.5000',
            'unite' => null,
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ];

        $result = $this->mapper->validateMappedRow($data);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('code_fournisseur', $result['errors'][0]['field']);
    }

    public function testValidateMappedRowInvalidPrice(): void
    {
        $data = [
            'code_fournisseur' => 'PROD001',
            'designation' => 'Tomate',
            'prix' => '-5.00',
            'unite' => null,
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ];

        $result = $this->mapper->validateMappedRow($data);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('prix', $result['errors'][0]['field']);
    }

    public function testColumnMappingConfigRequiredFields(): void
    {
        $config = new ColumnMappingConfig(
            mapping: [
                0 => 'code_fournisseur',
                1 => 'designation',
                2 => 'prix',
            ],
        );

        $this->assertTrue($config->hasRequiredFields());
        $this->assertEmpty($config->getMissingRequiredFields());
    }

    public function testColumnMappingConfigMissingRequired(): void
    {
        $config = new ColumnMappingConfig(
            mapping: [
                0 => 'code_fournisseur',
                1 => 'designation',
                // Missing prix
            ],
        );

        $this->assertFalse($config->hasRequiredFields());
        $this->assertContains('Prix unitaire HT', $config->getMissingRequiredFields());
    }
}
