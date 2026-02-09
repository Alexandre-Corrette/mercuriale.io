<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\Import\ImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MercurialeFileParser
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const MAX_ROWS = 5000;

    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const ALLOWED_EXTENSIONS = ['csv', 'xlsx'];

    private const CSV_DELIMITERS = [';', ',', "\t", '|'];

    private const FORMULA_PREFIXES = ['=', '+', '-', '@'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse an uploaded file and return its data.
     *
     * @return array{headers: array<string>, rows: array<array<string>>, totalRows: int, detectedDelimiter: ?string}
     *
     * @throws ImportException
     */
    public function parse(UploadedFile $file): array
    {
        $this->validateFile($file);

        $extension = strtolower($file->getClientOriginalExtension());

        try {
            if ($extension === 'csv') {
                return $this->parseCsv($file->getPathname());
            }

            return $this->parseXlsx($file->getPathname());
        } catch (ImportException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse file', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            throw new ImportException(ImportException::ERROR_PARSE_FAILED, null, $e);
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw ImportException::fileTooLarge(self::MAX_FILE_SIZE);
        }

        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ImportException(ImportException::ERROR_INVALID_FORMAT);
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if ($mimeType !== null && !\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            // Some systems report different MIME types, so also check by extension
            if ($extension === 'csv' && !\in_array($mimeType, ['text/csv', 'text/plain', 'application/csv'], true)) {
                $this->logger->warning('Unexpected MIME type for CSV', [
                    'mimeType' => $mimeType,
                    'filename' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Validate magic bytes for XLSX files
        if ($extension === 'xlsx') {
            $this->validateXlsxMagicBytes($file->getPathname());
        }
    }

    private function validateXlsxMagicBytes(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new ImportException(ImportException::ERROR_PARSE_FAILED);
        }

        $bytes = fread($handle, 4);
        fclose($handle);

        // XLSX files are ZIP archives, which start with PK\x03\x04
        if ($bytes !== "PK\x03\x04") {
            throw new ImportException(
                ImportException::ERROR_INVALID_FORMAT,
                'Le fichier XLSX semble corrompu ou n\'est pas un fichier Excel valide',
            );
        }
    }

    /**
     * @return array{headers: array<string>, rows: array<array<string>>, totalRows: int, detectedDelimiter: string}
     */
    private function parseCsv(string $path): array
    {
        $delimiter = $this->detectCsvDelimiter($path);

        $reader = new CsvReader();
        $reader->setDelimiter($delimiter);
        $reader->setInputEncoding('UTF-8');

        // Try UTF-8 first, fallback to ISO-8859-1
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ImportException(ImportException::ERROR_PARSE_FAILED);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $reader->setInputEncoding('ISO-8859-1');
        }

        $spreadsheet = $reader->load($path);
        $data = $spreadsheet->getActiveSheet()->toArray();

        return $this->processSheetData($data, $delimiter);
    }

    /**
     * @return array{headers: array<string>, rows: array<array<string>>, totalRows: int, detectedDelimiter: null}
     */
    private function parseXlsx(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $data = $spreadsheet->getActiveSheet()->toArray();

        return $this->processSheetData($data, null);
    }

    /**
     * @return array{headers: array<string>, rows: array<array<string>>, totalRows: int, detectedDelimiter: ?string}
     */
    private function processSheetData(array $data, ?string $delimiter): array
    {
        if (empty($data)) {
            throw new ImportException(ImportException::ERROR_EMPTY_FILE);
        }

        // Remove completely empty rows
        $data = array_filter($data, fn (array $row) => !$this->isEmptyRow($row));
        $data = array_values($data);

        if (empty($data)) {
            throw new ImportException(ImportException::ERROR_EMPTY_FILE);
        }

        $totalRows = \count($data);
        if ($totalRows > self::MAX_ROWS + 1) { // +1 for header
            throw ImportException::tooManyRows(self::MAX_ROWS, $totalRows - 1);
        }

        // First row is assumed to be headers
        $headers = array_map(fn ($h) => $this->sanitizeCell((string) ($h ?? '')), array_shift($data));

        // Process and sanitize all rows
        $rows = [];
        foreach ($data as $rowIndex => $row) {
            $sanitizedRow = [];
            foreach ($row as $colIndex => $cell) {
                $sanitizedRow[] = $this->sanitizeCell((string) ($cell ?? ''), $rowIndex + 2, $colIndex);
            }
            $rows[] = $sanitizedRow;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'totalRows' => \count($rows),
            'detectedDelimiter' => $delimiter,
        ];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize a cell value: trim, detect formulas, escape HTML.
     */
    private function sanitizeCell(string $value, int $row = 0, int $col = 0): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Check for formula injection (CSV injection attack)
        $firstChar = $value[0];
        if (\in_array($firstChar, self::FORMULA_PREFIXES, true)) {
            $this->logger->warning('Potential formula injection detected', [
                'row' => $row,
                'col' => $col,
                'value' => substr($value, 0, 50),
            ]);
            throw ImportException::maliciousContent('formula', $row);
        }

        // Escape HTML special characters to prevent XSS
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ';'; // Default to semicolon
        }

        // Read first few lines to detect delimiter
        $lines = [];
        for ($i = 0; $i < 5 && ($line = fgets($handle)) !== false; ++$i) {
            $lines[] = $line;
        }
        fclose($handle);

        if (empty($lines)) {
            return ';';
        }

        $delimiterCounts = [];
        foreach (self::CSV_DELIMITERS as $delimiter) {
            $count = 0;
            foreach ($lines as $line) {
                $count += substr_count($line, $delimiter);
            }
            $delimiterCounts[$delimiter] = $count;
        }

        // Return the delimiter with the highest count
        arsort($delimiterCounts);
        $bestDelimiter = array_key_first($delimiterCounts);

        $this->logger->debug('CSV delimiter detected', [
            'delimiter' => $bestDelimiter === "\t" ? 'TAB' : $bestDelimiter,
            'counts' => $delimiterCounts,
        ]);

        return $bestDelimiter;
    }

    /**
     * Detect headers that look like standard mercuriale columns.
     *
     * @return array<string, int> field name => column index
     */
    public function detectColumnMapping(array $headers): array
    {
        $mapping = [];

        $patterns = [
            'code_fournisseur' => [
                '/^code$/i',
                '/^code.*produit/i',
                '/^code.*fourn/i',
                '/^ref/i',
                '/^reference/i',
                '/^sku/i',
                '/^article/i',
            ],
            'designation' => [
                '/^design/i',
                '/^libelle/i',
                '/^nom.*produit/i',
                '/^produit$/i',
                '/^description/i',
                '/^intitul/i',
            ],
            'unite' => [
                '/^unit/i',
                '/^u\.?v\.?c\.?/i',
                '/^conditionnement$/i',
                '/^cond\.?$/i',
            ],
            'prix' => [
                '/^prix/i',
                '/^p\.?u\.?/i',
                '/^tarif/i',
                '/^montant/i',
                '/^ht$/i',
                '/^prix.*ht/i',
                '/^prix.*unit/i',
            ],
            'conditionnement' => [
                '/^pcb$/i',
                '/^colisage/i',
                '/^qt[eé].*colis/i',
                '/^nb.*unit/i',
            ],
        ];

        foreach ($headers as $index => $header) {
            $normalizedHeader = strtolower(trim($header));

            foreach ($patterns as $field => $fieldPatterns) {
                if (isset($mapping[$field])) {
                    continue; // Already mapped
                }

                foreach ($fieldPatterns as $pattern) {
                    if (preg_match($pattern, $normalizedHeader)) {
                        $mapping[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }
}
