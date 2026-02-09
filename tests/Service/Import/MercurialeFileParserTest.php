<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Exception\Import\ImportException;
use App\Service\Import\MercurialeFileParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MercurialeFileParserTest extends TestCase
{
    private MockObject&LoggerInterface $logger;
    private MercurialeFileParser $parser;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->parser = new MercurialeFileParser($this->logger);
        $this->tempDir = sys_get_temp_dir() . '/mercuriale_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function testParseCsvWithSemicolon(): void
    {
        $content = "Code;Designation;Prix\nPROD001;Tomate;2,50\nPROD002;Carotte;1,80";
        $file = $this->createTempFile($content, 'test.csv');

        $result = $this->parser->parse($file);

        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('totalRows', $result);

        $this->assertEquals(['Code', 'Designation', 'Prix'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertEquals(2, $result['totalRows']);

        $this->assertEquals(['PROD001', 'Tomate', '2,50'], $result['rows'][0]);
        $this->assertEquals(['PROD002', 'Carotte', '1,80'], $result['rows'][1]);
    }

    public function testParseCsvWithComma(): void
    {
        $content = "Code,Designation,Prix\nPROD001,Tomate,2.50\nPROD002,Carotte,1.80";
        $file = $this->createTempFile($content, 'test.csv');

        $result = $this->parser->parse($file);

        $this->assertEquals(['Code', 'Designation', 'Prix'], $result['headers']);
        $this->assertCount(2, $result['rows']);
    }

    public function testEmptyFileThrowsException(): void
    {
        $file = $this->createTempFile('', 'empty.csv');

        $this->expectException(ImportException::class);
        $this->parser->parse($file);
    }

    public function testFileTooLargeThrowsException(): void
    {
        // Create a mock file that reports a large size
        $file = $this->createMock(UploadedFile::class);
        $file->method('getSize')->willReturn(11 * 1024 * 1024); // 11MB
        $file->method('getClientOriginalExtension')->willReturn('csv');

        $this->expectException(ImportException::class);
        $this->parser->parse($file);
    }

    public function testInvalidExtensionThrowsException(): void
    {
        $file = $this->createTempFile('test', 'test.txt', 'txt');

        $this->expectException(ImportException::class);
        $this->parser->parse($file);
    }

    public function testFormulaInjectionThrowsException(): void
    {
        $content = "Code;Designation;Prix\n=CMD|'/C calc'!A0;Tomate;2,50";
        $file = $this->createTempFile($content, 'malicious.csv');

        $this->expectException(ImportException::class);
        $this->parser->parse($file);
    }

    public function testDetectColumnMappingCode(): void
    {
        $headers = ['Code produit', 'Designation', 'Prix HT', 'Unite'];

        $mapping = $this->parser->detectColumnMapping($headers);

        $this->assertArrayHasKey('code_fournisseur', $mapping);
        $this->assertEquals(0, $mapping['code_fournisseur']);
    }

    public function testDetectColumnMappingDesignation(): void
    {
        $headers = ['REF', 'Libelle', 'Tarif', 'UVC'];

        $mapping = $this->parser->detectColumnMapping($headers);

        $this->assertArrayHasKey('designation', $mapping);
        $this->assertEquals(1, $mapping['designation']);
    }

    public function testDetectColumnMappingPrix(): void
    {
        $headers = ['Article', 'Nom', 'Prix unitaire HT', 'Conditionnement'];

        $mapping = $this->parser->detectColumnMapping($headers);

        $this->assertArrayHasKey('prix', $mapping);
        $this->assertEquals(2, $mapping['prix']);
    }

    public function testDetectColumnMappingUnite(): void
    {
        $headers = ['Code', 'Designation', 'PU', 'Unite de vente'];

        $mapping = $this->parser->detectColumnMapping($headers);

        $this->assertArrayHasKey('unite', $mapping);
        $this->assertEquals(3, $mapping['unite']);
    }

    public function testHtmlEscaping(): void
    {
        $content = "Code;Designation;Prix\nPROD001;<script>alert('xss')</script>;2,50";
        $file = $this->createTempFile($content, 'xss.csv');

        $result = $this->parser->parse($file);

        $this->assertStringNotContainsString('<script>', $result['rows'][0][1]);
        $this->assertStringContainsString('&lt;script&gt;', $result['rows'][0][1]);
    }

    public function testEmptyRowsAreRemoved(): void
    {
        $content = "Code;Designation;Prix\nPROD001;Tomate;2,50\n;;;\nPROD002;Carotte;1,80\n";
        $file = $this->createTempFile($content, 'with_empty.csv');

        $result = $this->parser->parse($file);

        $this->assertCount(2, $result['rows']);
    }

    public function testTooManyRowsThrowsException(): void
    {
        // Create a CSV with more than 5000 rows
        $lines = ["Code;Designation;Prix"];
        for ($i = 0; $i < 5002; $i++) {
            $lines[] = "PROD{$i};Product {$i};1.00";
        }
        $content = implode("\n", $lines);
        $file = $this->createTempFile($content, 'too_many.csv');

        $this->expectException(ImportException::class);
        $this->parser->parse($file);
    }

    private function createTempFile(string $content, string $filename, string $extension = 'csv'): UploadedFile
    {
        $path = $this->tempDir . '/' . $filename;
        file_put_contents($path, $content);

        return new UploadedFile(
            $path,
            $filename,
            $extension === 'csv' ? 'text/csv' : 'text/plain',
            null,
            true, // test mode
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
