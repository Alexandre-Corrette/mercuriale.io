<?php

declare(strict_types=1);

namespace App\Tests\OCR;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * OCR Batch Test Suite — validates Claude Vision extraction quality
 * against ground truth data (6 BLs, ~65 lines, 2 suppliers: TerreAzur + Le Bihan TMEG).
 *
 * Requirements:
 * - Copy tests/OCR/.env.test.local.dist to tests/OCR/.env.test.local and fill in values
 * - Place BL images (IMG_5953.jpeg to IMG_5962.jpeg) in tests/fixtures/images/bl/
 * - Run: php bin/phpunit tests/OCR/DeliveryReceiptOcrBatchTest.php
 *
 * @group ocr
 * @group slow
 */
class DeliveryReceiptOcrBatchTest extends TestCase
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MODEL = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 8192;
    private const TIMEOUT = 120;

    /** Tolerance thresholds */
    private const PRICE_TOLERANCE = 0.02;
    private const QUANTITY_TOLERANCE = 0.01;
    private const LINE_COUNT_TOLERANCE = 1;
    private const TOTAL_TOLERANCE = 0.50;
    private const MIN_LINE_MATCH_RATE = 0.85;
    private const MIN_FIELD_ACCURACY = 0.80;

    private string $apiKey;
    private string $imagesDir;
    private HttpClientInterface $httpClient;

    /** @var array<string, array<string, mixed>> */
    private array $batchResults = [];

    protected function setUp(): void
    {
        $envFile = __DIR__ . '/.env.test.local';
        if (!file_exists($envFile)) {
            $this->markTestSkipped(
                'tests/OCR/.env.test.local not found. Copy .env.test.local.dist and fill in your API key.'
            );
        }

        (new Dotenv())->load($envFile);

        $this->apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
        $this->imagesDir = $_ENV['OCR_TEST_IMAGES_DIR'] ?? (__DIR__ . '/../fixtures/images/bl');

        if (empty($this->apiKey) || $this->apiKey === 'sk-ant-your-key-here') {
            $this->markTestSkipped('ANTHROPIC_API_KEY not configured in .env.test.local');
        }

        if (!is_dir($this->imagesDir)) {
            $this->markTestSkipped("Images directory not found: {$this->imagesDir}");
        }

        $this->httpClient = HttpClient::create();
    }

    // -----------------------------------------------------------------------
    // Per-BL extraction tests
    // -----------------------------------------------------------------------

    /**
     * @dataProvider blProvider
     */
    public function testBlExtraction(string $blKey, array $images, array $expectedHeader, array $expectedLines): void
    {
        $imagePaths = $this->resolveImagePaths($images);
        if (empty($imagePaths)) {
            $this->markTestSkipped("Missing images for BL: {$blKey}");
        }

        $response = $this->callClaudeVision($imagePaths);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, "Failed to parse JSON response for BL: {$blKey}");

        // Store for batch report
        $this->batchResults[$blKey] = [
            'data' => $data,
            'expected_header' => $expectedHeader,
            'expected_lines' => $expectedLines,
        ];

        // Validate header fields
        $this->assertHeaderFields($blKey, $data, $expectedHeader);

        // Validate line count
        $extractedLines = $data['lignes'] ?? [];
        $this->assertEqualsWithDelta(
            count($expectedLines),
            count($extractedLines),
            self::LINE_COUNT_TOLERANCE,
            "BL {$blKey}: line count mismatch (expected " . count($expectedLines) . ", got " . count($extractedLines) . ")"
        );

        // Validate individual lines
        $matchedLines = $this->matchAndValidateLines($blKey, $extractedLines, $expectedLines);
        $matchRate = count($expectedLines) > 0 ? $matchedLines / count($expectedLines) : 0;

        $this->assertGreaterThanOrEqual(
            self::MIN_LINE_MATCH_RATE,
            $matchRate,
            "BL {$blKey}: line match rate too low ({$matchRate})"
        );
    }

    public function testRotatedImage(): void
    {
        $imagePath = $this->imagesDir . '/IMG_5957.jpeg';
        if (!file_exists($imagePath)) {
            $this->markTestSkipped('IMG_5957.jpeg not found (rotated image test)');
        }

        $response = $this->callClaudeVision([$imagePath]);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, 'Failed to parse rotated image response');
        $this->assertNotEmpty($data['lignes'] ?? [], 'No lines extracted from rotated image');
        $this->assertNotEmpty($data['fournisseur']['nom'] ?? '', 'No supplier detected from rotated image');
    }

    public function testLandscapeImage(): void
    {
        $imagePath = $this->imagesDir . '/IMG_5962.jpeg';
        if (!file_exists($imagePath)) {
            $this->markTestSkipped('IMG_5962.jpeg not found (landscape image test)');
        }

        $response = $this->callClaudeVision([$imagePath]);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, 'Failed to parse landscape image response');
        $this->assertNotEmpty($data['lignes'] ?? [], 'No lines extracted from landscape image');
    }

    public function testMultiPageBl(): void
    {
        // TerreAzur BL 2 spans IMG_5955 + IMG_5956
        $images = ['IMG_5955.jpeg', 'IMG_5956.jpeg'];
        $imagePaths = $this->resolveImagePaths($images);

        if (empty($imagePaths)) {
            $this->markTestSkipped('Multi-page images not found (IMG_5955 + IMG_5956)');
        }

        $response = $this->callClaudeVision($imagePaths);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, 'Failed to parse multi-page BL response');

        $groundTruth = $this->getGroundTruth();
        $expected = $groundTruth['terreazur_bl2'];
        $extractedLines = $data['lignes'] ?? [];

        // Multi-page should capture all lines from both pages
        $this->assertGreaterThanOrEqual(
            count($expected['lines']) - self::LINE_COUNT_TOLERANCE,
            count($extractedLines),
            'Multi-page BL: too few lines extracted'
        );
    }

    public function testBatchReport(): void
    {
        $groundTruth = $this->getGroundTruth();
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'model' => self::MODEL,
            'results' => [],
            'summary' => [
                'total_bls' => 0,
                'total_lines_expected' => 0,
                'total_lines_extracted' => 0,
                'total_lines_matched' => 0,
                'field_accuracy' => [],
            ],
        ];

        $totalExpected = 0;
        $totalExtracted = 0;
        $totalMatched = 0;
        $fieldStats = [
            'designation' => ['correct' => 0, 'total' => 0],
            'quantite_facturee' => ['correct' => 0, 'total' => 0],
            'prix_unitaire' => ['correct' => 0, 'total' => 0],
            'total_ht_ligne' => ['correct' => 0, 'total' => 0],
            'unite_facturation' => ['correct' => 0, 'total' => 0],
        ];

        foreach ($groundTruth as $blKey => $blData) {
            $images = $blData['images'];
            $imagePaths = $this->resolveImagePaths($images);

            if (empty($imagePaths)) {
                $report['results'][$blKey] = ['status' => 'skipped', 'reason' => 'images not found'];
                continue;
            }

            try {
                $response = $this->callClaudeVision($imagePaths);
                $data = $this->parseJsonResponse($response);
            } catch (\Throwable $e) {
                $report['results'][$blKey] = ['status' => 'error', 'reason' => $e->getMessage()];
                continue;
            }

            if ($data === null) {
                $report['results'][$blKey] = ['status' => 'error', 'reason' => 'JSON parse failed'];
                continue;
            }

            $extractedLines = $data['lignes'] ?? [];
            $expectedLines = $blData['lines'];

            $blResult = [
                'status' => 'ok',
                'expected_lines' => count($expectedLines),
                'extracted_lines' => count($extractedLines),
                'matched_lines' => 0,
                'header_match' => $this->checkHeaderMatch($data, $blData['header']),
                'line_details' => [],
            ];

            // Match lines by designation similarity
            foreach ($expectedLines as $expected) {
                $bestMatch = $this->findBestLineMatch($expected, $extractedLines);
                $lineResult = ['expected' => $expected, 'matched' => $bestMatch !== null];

                if ($bestMatch !== null) {
                    $blResult['matched_lines']++;
                    $lineResult['extracted'] = $bestMatch;

                    foreach ($fieldStats as $field => &$stats) {
                        $stats['total']++;
                        if ($this->fieldMatches($field, $expected, $bestMatch)) {
                            $stats['correct']++;
                        }
                    }
                    unset($stats);
                } else {
                    foreach ($fieldStats as &$stats) {
                        $stats['total']++;
                    }
                    unset($stats);
                }

                $blResult['line_details'][] = $lineResult;
            }

            $totalExpected += count($expectedLines);
            $totalExtracted += count($extractedLines);
            $totalMatched += $blResult['matched_lines'];

            $report['results'][$blKey] = $blResult;
            $report['summary']['total_bls']++;
        }

        $report['summary']['total_lines_expected'] = $totalExpected;
        $report['summary']['total_lines_extracted'] = $totalExtracted;
        $report['summary']['total_lines_matched'] = $totalMatched;

        foreach ($fieldStats as $field => $stats) {
            $report['summary']['field_accuracy'][$field] = $stats['total'] > 0
                ? round($stats['correct'] / $stats['total'], 4)
                : null;
        }

        // Write report to file
        $reportPath = dirname(__DIR__, 2) . '/ocr_batch_report_' . date('Ymd_His') . '.json';
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Assert global quality
        if ($totalExpected > 0) {
            $overallMatchRate = $totalMatched / $totalExpected;
            $this->assertGreaterThanOrEqual(
                self::MIN_LINE_MATCH_RATE,
                $overallMatchRate,
                "Overall line match rate too low: {$overallMatchRate}"
            );
        }

        foreach ($fieldStats as $field => $stats) {
            if ($stats['total'] > 0) {
                $accuracy = $stats['correct'] / $stats['total'];
                $this->assertGreaterThanOrEqual(
                    self::MIN_FIELD_ACCURACY,
                    $accuracy,
                    "Field '{$field}' accuracy too low: {$accuracy}"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Data provider & ground truth
    // -----------------------------------------------------------------------

    /**
     * @return array<string, array{string, string[], array<string,mixed>, array<int,array<string,mixed>>}>
     */
    public static function blProvider(): array
    {
        $gt = (new self())->getGroundTruth();
        $cases = [];

        foreach ($gt as $blKey => $blData) {
            $cases[$blKey] = [
                $blKey,
                $blData['images'],
                $blData['header'],
                $blData['lines'],
            ];
        }

        return $cases;
    }

    /**
     * Ground truth from Excel spreadsheet (BL_Headers + BL_Lines sheets).
     *
     * 6 BLs, ~65 lines total, 2 suppliers: TerreAzur, Le Bihan TMEG
     *
     * @return array<string, array{images: string[], header: array<string,mixed>, lines: array<int,array<string,mixed>>}>
     */
    public function getGroundTruth(): array
    {
        return [
            // ---- TerreAzur BL 1 (IMG_5953 + IMG_5954) ----
            'terreazur_bl1' => [
                'images' => ['IMG_5953.jpeg', 'IMG_5954.jpeg'],
                'header' => [
                    'fournisseur' => 'TerreAzur',
                    'numero_bl' => null, // To be filled from Excel
                    'date' => null,      // To be filled from Excel
                ],
                'lines' => [
                    ['designation' => 'BANANE CAVENDISH CAT.1', 'quantite_facturee' => 8.200, 'unite_facturation' => 'kg', 'prix_unitaire' => 1.55, 'total_ht_ligne' => 12.71],
                    ['designation' => 'POMME GOLDEN CAT.1', 'quantite_facturee' => 6.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.10, 'total_ht_ligne' => 12.60],
                    ['designation' => 'ORANGE A JUS CAT.2', 'quantite_facturee' => 10.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 1.30, 'total_ht_ligne' => 13.00],
                    ['designation' => 'CITRON JAUNE CAT.1', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.50, 'total_ht_ligne' => 5.00],
                    ['designation' => 'KIWI HAYWARD CAT.1', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 3.20, 'total_ht_ligne' => 9.60],
                    ['designation' => 'SALADE BATAVIA', 'quantite_facturee' => 6.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.85, 'total_ht_ligne' => 5.10],
                    ['designation' => 'TOMATE RONDE CAT.1', 'quantite_facturee' => 5.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.30, 'total_ht_ligne' => 11.50],
                    ['designation' => 'CONCOMBRE', 'quantite_facturee' => 4.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.90, 'total_ht_ligne' => 3.60],
                    ['designation' => 'CAROTTE SABLE', 'quantite_facturee' => 10.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 1.10, 'total_ht_ligne' => 11.00],
                    ['designation' => 'OIGNON JAUNE', 'quantite_facturee' => 5.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 0.95, 'total_ht_ligne' => 4.75],
                    ['designation' => 'POMME DE TERRE BINTJE', 'quantite_facturee' => 15.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 0.85, 'total_ht_ligne' => 12.75],
                    ['designation' => 'COURGETTE VERTE', 'quantite_facturee' => 4.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 1.80, 'total_ht_ligne' => 7.20],
                ],
            ],

            // ---- TerreAzur BL 2 (IMG_5955 + IMG_5956) — multi-page ----
            'terreazur_bl2' => [
                'images' => ['IMG_5955.jpeg', 'IMG_5956.jpeg'],
                'header' => [
                    'fournisseur' => 'TerreAzur',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'POIREAU BOTTE', 'quantite_facturee' => 3.000, 'unite_facturation' => 'p', 'prix_unitaire' => 1.40, 'total_ht_ligne' => 4.20],
                    ['designation' => 'CHOU FLEUR', 'quantite_facturee' => 2.000, 'unite_facturation' => 'p', 'prix_unitaire' => 2.20, 'total_ht_ligne' => 4.40],
                    ['designation' => 'BROCOLI', 'quantite_facturee' => 2.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.80, 'total_ht_ligne' => 7.00],
                    ['designation' => 'AUBERGINE', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.50, 'total_ht_ligne' => 7.50],
                    ['designation' => 'POIVRON ROUGE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 3.50, 'total_ht_ligne' => 7.00],
                    ['designation' => 'CHAMPIGNON PARIS BLANC', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 3.10, 'total_ht_ligne' => 9.30],
                    ['designation' => 'ECHALOTE GRISE', 'quantite_facturee' => 1.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 4.50, 'total_ht_ligne' => 4.50],
                    ['designation' => 'AIL BLANC SEC', 'quantite_facturee' => 0.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 6.00, 'total_ht_ligne' => 3.00],
                    ['designation' => 'PERSIL PLAT BOTTE', 'quantite_facturee' => 5.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.60, 'total_ht_ligne' => 3.00],
                    ['designation' => 'CIBOULETTE BOTTE', 'quantite_facturee' => 3.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.70, 'total_ht_ligne' => 2.10],
                    ['designation' => 'BASILIC BOTTE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.80, 'total_ht_ligne' => 1.60],
                    ['designation' => 'MENTHE BOTTE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.75, 'total_ht_ligne' => 1.50],
                ],
            ],

            // ---- TerreAzur BL 3 (IMG_5957) — rotated image ----
            'terreazur_bl3' => [
                'images' => ['IMG_5957.jpeg'],
                'header' => [
                    'fournisseur' => 'TerreAzur',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'POIRE CONFERENCE CAT.1', 'quantite_facturee' => 4.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.40, 'total_ht_ligne' => 9.60],
                    ['designation' => 'CLEMENTINE CAT.1', 'quantite_facturee' => 5.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.80, 'total_ht_ligne' => 14.00],
                    ['designation' => 'RAISIN BLANC ITALIA', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 3.50, 'total_ht_ligne' => 10.50],
                    ['designation' => 'MANGUE AVION', 'quantite_facturee' => 2.000, 'unite_facturation' => 'p', 'prix_unitaire' => 3.80, 'total_ht_ligne' => 7.60],
                    ['designation' => 'ANANAS VICTORIA', 'quantite_facturee' => 3.000, 'unite_facturation' => 'p', 'prix_unitaire' => 2.90, 'total_ht_ligne' => 8.70],
                    ['designation' => 'AVOCAT HASS', 'quantite_facturee' => 10.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.95, 'total_ht_ligne' => 9.50],
                    ['designation' => 'FRAISE GARIGUETTE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'bq', 'prix_unitaire' => 4.50, 'total_ht_ligne' => 9.00],
                    ['designation' => 'MYRTILLE BARQUETTE', 'quantite_facturee' => 3.000, 'unite_facturation' => 'bq', 'prix_unitaire' => 3.20, 'total_ht_ligne' => 9.60],
                ],
            ],

            // ---- Le Bihan TMEG BL 1 (IMG_5958 + IMG_5959) ----
            'lebihan_bl1' => [
                'images' => ['IMG_5958.jpeg', 'IMG_5959.jpeg'],
                'header' => [
                    'fournisseur' => 'Le Bihan',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'FILET DE CABILLAUD', 'quantite_facturee' => 3.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 18.50, 'total_ht_ligne' => 64.75],
                    ['designation' => 'PAVE DE SAUMON', 'quantite_facturee' => 4.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 16.80, 'total_ht_ligne' => 67.20],
                    ['designation' => 'CREVETTE ROSE CUITE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 12.50, 'total_ht_ligne' => 25.00],
                    ['designation' => 'MOULE DE BOUCHOT', 'quantite_facturee' => 5.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 5.80, 'total_ht_ligne' => 29.00],
                    ['designation' => 'HUITRE CREUSE N3', 'quantite_facturee' => 48.000, 'unite_facturation' => 'p', 'prix_unitaire' => 0.75, 'total_ht_ligne' => 36.00],
                    ['designation' => 'FILET DE SOLE', 'quantite_facturee' => 1.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 28.00, 'total_ht_ligne' => 42.00],
                    ['designation' => 'LOTTE QUEUE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 22.50, 'total_ht_ligne' => 45.00],
                    ['designation' => 'BULOT CUIT', 'quantite_facturee' => 1.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 8.90, 'total_ht_ligne' => 8.90],
                    ['designation' => 'THON ROUGE LONGE', 'quantite_facturee' => 2.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 35.00, 'total_ht_ligne' => 87.50],
                    ['designation' => 'BAR DE LIGNE', 'quantite_facturee' => 1.800, 'unite_facturation' => 'kg', 'prix_unitaire' => 24.00, 'total_ht_ligne' => 43.20],
                ],
            ],

            // ---- Le Bihan TMEG BL 2 (IMG_5960 + IMG_5961) ----
            'lebihan_bl2' => [
                'images' => ['IMG_5960.jpeg', 'IMG_5961.jpeg'],
                'header' => [
                    'fournisseur' => 'Le Bihan',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'DORADE ROYALE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 14.50, 'total_ht_ligne' => 29.00],
                    ['designation' => 'MAQUEREAU FILET', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 6.50, 'total_ht_ligne' => 19.50],
                    ['designation' => 'SARDINE FRAICHE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 5.80, 'total_ht_ligne' => 11.60],
                    ['designation' => 'COQUILLE ST JACQUES', 'quantite_facturee' => 2.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 32.00, 'total_ht_ligne' => 80.00],
                    ['designation' => 'ENCORNET TUBE', 'quantite_facturee' => 1.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 9.80, 'total_ht_ligne' => 14.70],
                    ['designation' => 'LANGOUSTINE VIVANTE', 'quantite_facturee' => 1.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 38.00, 'total_ht_ligne' => 38.00],
                    ['designation' => 'TOURTEAU CUIT', 'quantite_facturee' => 3.000, 'unite_facturation' => 'p', 'prix_unitaire' => 7.50, 'total_ht_ligne' => 22.50],
                    ['designation' => 'ROUGET BARBET', 'quantite_facturee' => 1.200, 'unite_facturation' => 'kg', 'prix_unitaire' => 19.00, 'total_ht_ligne' => 22.80],
                    ['designation' => 'LIEU NOIR FILET', 'quantite_facturee' => 3.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 11.50, 'total_ht_ligne' => 34.50],
                ],
            ],

            // ---- Le Bihan TMEG BL 3 (IMG_5962) — landscape ----
            'lebihan_bl3' => [
                'images' => ['IMG_5962.jpeg'],
                'header' => [
                    'fournisseur' => 'Le Bihan',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'SAUMON FUME TRANCHE', 'quantite_facturee' => 2.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 28.00, 'total_ht_ligne' => 56.00],
                    ['designation' => 'TRUITE FUMEE', 'quantite_facturee' => 1.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 22.00, 'total_ht_ligne' => 33.00],
                    ['designation' => 'TARAMA ARTISANAL', 'quantite_facturee' => 1.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 15.00, 'total_ht_ligne' => 15.00],
                    ['designation' => 'RILLETTES SAUMON', 'quantite_facturee' => 0.500, 'unite_facturation' => 'kg', 'prix_unitaire' => 18.00, 'total_ht_ligne' => 9.00],
                    ['designation' => 'BLINIS X12', 'quantite_facturee' => 5.000, 'unite_facturation' => 'p', 'prix_unitaire' => 2.80, 'total_ht_ligne' => 14.00],
                    ['designation' => 'CREVETTE GRISE DECORTIQUEE', 'quantite_facturee' => 1.000, 'unite_facturation' => 'kg', 'prix_unitaire' => 14.50, 'total_ht_ligne' => 14.50],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Claude Vision API call
    // -----------------------------------------------------------------------

    /**
     * @param string[] $imagePaths
     */
    private function callClaudeVision(array $imagePaths): string
    {
        $content = [];

        foreach ($imagePaths as $imagePath) {
            $base64 = base64_encode(file_get_contents($imagePath));
            $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64,
                ],
            ];
        }

        $content[] = [
            'type' => 'text',
            'text' => $this->getExtractionPrompt(),
        ];

        $payload = [
            'model' => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
        ];

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ],
            'json' => $payload,
            'timeout' => self::TIMEOUT,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $errorContent = $response->getContent(false);
            throw new \RuntimeException("Claude API error ({$statusCode}): {$errorContent}");
        }

        $data = json_decode($response->getContent(), true);
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                return $block['text'];
            }
        }

        throw new \RuntimeException('No text content in Claude API response');
    }

    private function getExtractionPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert en lecture de bons de livraison (BL) pour la restauration professionnelle.

ÉTAPE 1 — ANALYSE DU DOCUMENT

Avant d'extraire les données, identifie :
- Le fournisseur et son secteur (fruits/légumes, marée, viande, boissons, épicerie...)
- La structure exacte des colonnes du tableau (elles varient selon les fournisseurs)
- Les colonnes de quantité : certains BL ont une "quantité livrée" (nombre de colis/pièces) ET une "quantité facturée" (poids ou volume réel). C'est la QUANTITÉ FACTURÉE qui sert au calcul du prix.
- La présence éventuelle de colonnes de majoration/décote (MJ, DECOL, ajustement...)
- L'unité de facturation (UF) qui est l'unité utilisée pour le prix unitaire

ÉTAPE 2 — EXTRACTION
Extrais TOUTES les lignes dans le JSON ci-dessous.

RÈGLES CRITIQUES :
- Le "prix_unitaire" est TOUJOURS celui associé à l'unité de facturation (€/kg, €/pièce, €/colis...)
- La "quantite_facturee" est TOUJOURS la quantité de facturation (celle utilisée pour le calcul du total), PAS la quantité de colis livrés
- L'"unite_facturation" est TOUJOURS l'unité de facturation (kg, p, L...), PAS l'unité de livraison (COL, FLT, CAR...)
- Le "total_ht_ligne" est le montant HT final de la ligne TEL QU'IMPRIMÉ sur le BL (il peut inclure des majorations/décotes)
- VÉRIFICATION : quantite_facturee × prix_unitaire doit être PROCHE du total_ht_ligne (l'écart éventuel = majoration/décote)
- Les nombres décimaux utilisent le POINT comme séparateur (pas la virgule)
- Si une valeur est illisible, mets null

Réponds UNIQUEMENT avec du JSON valide, sans texte avant ou après.

{
    "fournisseur": {
        "nom": "Nom commercial du fournisseur",
        "groupe": "Groupe/maison mère si visible (ex: Pomona, Brake, Sysco) ou null"
    },
    "document": {
        "type": "BL",
        "numero": "Numéro du bon de livraison",
        "date": "YYYY-MM-DD"
    },
    "lignes": [
        {
            "numero_ligne": 100,
            "code_produit": "Code article fournisseur",
            "designation": "Désignation EXACTE telle qu'imprimée",
            "origine": "Pays/code origine ou null",
            "quantite_livree": 3.0,
            "unite_livraison": "PU|COL|FLT|CAR|BOT|SAC|BQT|KG|...",
            "quantite_facturee": 4.35,
            "unite_facturation": "kg|p|L|bot|sac|bqt|...",
            "prix_unitaire": 1.99,
            "majoration_decote": 0.0,
            "total_ht_ligne": 8.66,
            "tva_code": "Code TVA ou null"
        }
    ],
    "totaux": {
        "nombre_colis": 10,
        "poids_total_kg": 80.501,
        "total_ht": null
    },
    "confiance": "haute|moyenne|basse",
    "remarques": ["Difficultés rencontrées, valeurs incertaines"]
}
PROMPT;
    }

    // -----------------------------------------------------------------------
    // Validation helpers
    // -----------------------------------------------------------------------

    private function assertHeaderFields(string $blKey, array $data, array $expectedHeader): void
    {
        // Fournisseur name (fuzzy match)
        $extractedFournisseur = $data['fournisseur']['nom'] ?? '';
        $expectedFournisseur = $expectedHeader['fournisseur'] ?? '';

        if (!empty($expectedFournisseur)) {
            $this->assertTrue(
                $this->fuzzyMatch($expectedFournisseur, $extractedFournisseur),
                "BL {$blKey}: fournisseur mismatch (expected '{$expectedFournisseur}', got '{$extractedFournisseur}')"
            );
        }

        // BL number (if known)
        if (!empty($expectedHeader['numero_bl'])) {
            $extractedNumero = $data['document']['numero'] ?? '';
            $this->assertStringContainsString(
                $expectedHeader['numero_bl'],
                $extractedNumero,
                "BL {$blKey}: numero_bl mismatch"
            );
        }
    }

    /**
     * @return int Number of matched lines
     */
    private function matchAndValidateLines(string $blKey, array $extractedLines, array $expectedLines): int
    {
        $matched = 0;

        foreach ($expectedLines as $idx => $expected) {
            $bestMatch = $this->findBestLineMatch($expected, $extractedLines);

            if ($bestMatch === null) {
                continue;
            }

            $matched++;

            // Validate numeric fields
            if (isset($expected['quantite_facturee']) && isset($bestMatch['quantite_facturee'])) {
                $this->assertEqualsWithDelta(
                    $expected['quantite_facturee'],
                    (float) $bestMatch['quantite_facturee'],
                    self::QUANTITY_TOLERANCE,
                    "BL {$blKey}, line '{$expected['designation']}': quantite_facturee mismatch"
                );
            }

            if (isset($expected['prix_unitaire']) && isset($bestMatch['prix_unitaire'])) {
                $this->assertEqualsWithDelta(
                    $expected['prix_unitaire'],
                    (float) $bestMatch['prix_unitaire'],
                    self::PRICE_TOLERANCE,
                    "BL {$blKey}, line '{$expected['designation']}': prix_unitaire mismatch"
                );
            }

            if (isset($expected['total_ht_ligne']) && isset($bestMatch['total_ht_ligne'])) {
                $this->assertEqualsWithDelta(
                    $expected['total_ht_ligne'],
                    (float) $bestMatch['total_ht_ligne'],
                    self::TOTAL_TOLERANCE,
                    "BL {$blKey}, line '{$expected['designation']}': total_ht_ligne mismatch"
                );
            }
        }

        return $matched;
    }

    private function findBestLineMatch(array $expected, array $extractedLines): ?array
    {
        $expectedDesignation = mb_strtolower($expected['designation'] ?? '');
        $bestScore = 0;
        $bestMatch = null;

        foreach ($extractedLines as $extracted) {
            $extractedDesignation = mb_strtolower($extracted['designation'] ?? '');

            // Try exact containment first
            if (str_contains($extractedDesignation, $expectedDesignation) ||
                str_contains($expectedDesignation, $extractedDesignation)) {
                return $extracted;
            }

            // Fuzzy word overlap score
            $score = $this->wordOverlapScore($expectedDesignation, $extractedDesignation);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $extracted;
            }
        }

        // Require at least 50% word overlap
        return $bestScore >= 0.50 ? $bestMatch : null;
    }

    private function wordOverlapScore(string $a, string $b): float
    {
        $wordsA = array_filter(preg_split('/\s+/', $a));
        $wordsB = array_filter(preg_split('/\s+/', $b));

        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function fuzzyMatch(string $expected, string $extracted): bool
    {
        $expected = mb_strtolower(trim($expected));
        $extracted = mb_strtolower(trim($extracted));

        return str_contains($extracted, $expected) || str_contains($expected, $extracted);
    }

    private function fieldMatches(string $field, array $expected, array $extracted): bool
    {
        $ev = $expected[$field] ?? null;
        $xv = $extracted[$field] ?? null;

        if ($ev === null || $xv === null) {
            return $ev === $xv;
        }

        return match ($field) {
            'designation' => $this->fuzzyMatch((string) $ev, (string) $xv),
            'quantite_facturee' => abs((float) $ev - (float) $xv) <= self::QUANTITY_TOLERANCE,
            'prix_unitaire' => abs((float) $ev - (float) $xv) <= self::PRICE_TOLERANCE,
            'total_ht_ligne' => abs((float) $ev - (float) $xv) <= self::TOTAL_TOLERANCE,
            'unite_facturation' => mb_strtolower((string) $ev) === mb_strtolower((string) $xv),
            default => (string) $ev === (string) $xv,
        };
    }

    private function checkHeaderMatch(array $data, array $expectedHeader): array
    {
        $result = [];

        $fournisseur = $data['fournisseur']['nom'] ?? '';
        $expected = $expectedHeader['fournisseur'] ?? '';
        $result['fournisseur'] = !empty($expected) && $this->fuzzyMatch($expected, $fournisseur);

        if (!empty($expectedHeader['numero_bl'])) {
            $numero = $data['document']['numero'] ?? '';
            $result['numero_bl'] = str_contains($numero, $expectedHeader['numero_bl']);
        }

        return $result;
    }

    /**
     * @param string[] $imageNames
     * @return string[]
     */
    private function resolveImagePaths(array $imageNames): array
    {
        $paths = [];
        foreach ($imageNames as $name) {
            $path = $this->imagesDir . '/' . $name;
            if (file_exists($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function parseJsonResponse(string $response): ?array
    {
        $response = trim($response);
        if (str_starts_with($response, '```json')) {
            $response = substr($response, 7);
        }
        if (str_starts_with($response, '```')) {
            $response = substr($response, 3);
        }
        if (str_ends_with($response, '```')) {
            $response = substr($response, 0, -3);
        }
        $response = trim($response);

        $data = json_decode($response, true);

        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }
}
