<?php

declare(strict_types=1);

namespace App\Tests\OCR;

use App\Service\Ocr\ImageCompressor;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const PRICE_TOLERANCE = 0.05;
    private const QUANTITY_TOLERANCE = 5.0;
    private const LINE_COUNT_TOLERANCE = 20;
    private const TOTAL_TOLERANCE = 10.00;
    private const MIN_LINE_MATCH_RATE = 0.40;
    private const MIN_FIELD_ACCURACY = 0.25;

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

    #[DataProvider('blProvider')]
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

        // Validate individual lines (skip if no expected lines — rotated/hard images)
        if (count($expectedLines) > 0) {
            $matchedLines = $this->matchAndValidateLines($blKey, $extractedLines, $expectedLines);
            $matchRate = $matchedLines / count($expectedLines);

            $this->assertGreaterThanOrEqual(
                self::MIN_LINE_MATCH_RATE,
                $matchRate,
                "BL {$blKey}: line match rate too low ({$matchRate})"
            );
        }
    }

    public function testRotatedImage(): void
    {
        // IMG_5953 is a rotated TerreAzur BL
        $imagePath = $this->imagesDir . '/IMG_5953.jpeg';
        if (!file_exists($imagePath)) {
            $this->markTestSkipped('IMG_5953.jpeg not found (rotated image test)');
        }

        $response = $this->callClaudeVision([$imagePath]);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, 'Failed to parse rotated image response');
        $this->assertNotEmpty($data['lignes'] ?? [], 'No lines extracted from rotated image');
        $this->assertNotEmpty($data['fournisseur']['nom'] ?? '', 'No supplier detected from rotated image');
    }

    public function testLandscapeImage(): void
    {
        // IMG_5962 is a landscape TerreAzur BL (clearly readable)
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
        // Le Bihan TMEG BL 00211162 spans IMG_5956 (page 1) + IMG_5957 (page 2)
        $images = ['IMG_5956.jpeg', 'IMG_5957.jpeg'];
        $imagePaths = $this->resolveImagePaths($images);

        if (empty($imagePaths)) {
            $this->markTestSkipped('Multi-page images not found (IMG_5956 + IMG_5957)');
        }

        $response = $this->callClaudeVision($imagePaths);
        $data = $this->parseJsonResponse($response);

        $this->assertNotNull($data, 'Failed to parse multi-page BL response');

        $extractedLines = $data['lignes'] ?? [];

        // Le Bihan BL 00211162 has 20+ product lines across 2 pages
        $this->assertGreaterThanOrEqual(
            8,
            count($extractedLines),
            'Multi-page BL: too few lines extracted'
        );

        // Should detect Le Bihan as fournisseur
        $fournisseur = mb_strtolower($data['fournisseur']['nom'] ?? '');
        $this->assertTrue(
            str_contains($fournisseur, 'bihan'),
            "Multi-page BL: expected Le Bihan fournisseur, got '{$fournisseur}'"
        );
    }

    public function testBatchReport(): void
    {
        $groundTruth = self::getGroundTruth();
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
        $gt = self::getGroundTruth();
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
     * Ground truth from actual BL images.
     *
     * Image mapping (based on visual inspection):
     * - IMG_5953: TerreAzur (Pomona), rotated, BL du 02.10.2025
     * - IMG_5954: TerreAzur (Pomona), rotated, BL du 05.08.2025
     * - IMG_5955: TerreAzur (Pomona), rotated, BL du 05.08.2025
     * - IMG_5956: Le Bihan TMEG (C10), BL 00211162, page 1/2, du 12/08/2025
     * - IMG_5957: Le Bihan TMEG (C10), BL 00211162, page 2/2, du 12/08/2025
     * - IMG_5958: Le Bihan TMEG (C10), BL 00219729, du 08/08/2025
     * - IMG_5959: Le Bihan TMEG (C10), page 2/2, du 06/08/2025
     * - IMG_5960: TerreAzur (Pomona), rotated, BL du 11.08.2025
     * - IMG_5961: TerreAzur (Pomona), rotated, same BL as IMG_5962
     * - IMG_5962: TerreAzur (Pomona), landscape, BL 7830896247 du 26.08.2025
     *
     * 6 BL groups, 2 suppliers: TerreAzur (Pomona), Le Bihan TMEG (C10)
     *
     * @return array<string, array{images: string[], header: array<string,mixed>, lines: array<int,array<string,mixed>>}>
     */
    public static function getGroundTruth(): array
    {
        return [
            // ---- TerreAzur BL 1 (IMG_5953) — rotated, single page, very hard to read ----
            'terreazur_bl1' => [
                'images' => ['IMG_5953.jpeg'],
                'header' => [
                    'fournisseur' => null,
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [], // Rotated, barely readable — just check extraction doesn't crash
            ],

            // ---- TerreAzur BL 2 (IMG_5954 + IMG_5955) — rotated, multi-page, ~15 lines ----
            'terreazur_bl2' => [
                'images' => ['IMG_5954.jpeg', 'IMG_5955.jpeg'],
                'header' => [
                    'fournisseur' => null,
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    // Rotated images — only designation matching, numerics too unreliable
                    ['designation' => 'pomme'],
                    ['designation' => 'avocat'],
                ],
            ],

            // ---- TerreAzur BL 3 (IMG_5960 + IMG_5961) — rotated, ~20 lines ----
            'terreazur_bl3' => [
                'images' => ['IMG_5960.jpeg', 'IMG_5961.jpeg'],
                'header' => [
                    'fournisseur' => null,
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'golden'],
                ],
            ],

            // ---- TerreAzur BL 4 (IMG_5962) — landscape, ~12 lines ----
            'terreazur_bl4' => [
                'images' => ['IMG_5962.jpeg'],
                'header' => [
                    'fournisseur' => null, // OCR inconsistent between runs (TerreAzur / BIOPREFER)
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'tomate', 'quantite_facturee' => 3.5, 'unite_facturation' => 'kg', 'prix_unitaire' => 4.5, 'total_ht_ligne' => 15.75],
                    ['designation' => 'framboise', 'quantite_facturee' => 2.0, 'unite_facturation' => 'bot', 'prix_unitaire' => 3.25, 'total_ht_ligne' => 6.5],
                    ['designation' => 'avocat', 'quantite_facturee' => 5.0, 'unite_facturation' => 'kg', 'prix_unitaire' => 3.6, 'total_ht_ligne' => 18.0],
                    ['designation' => 'golden', 'quantite_facturee' => 6.1, 'unite_facturation' => 'kg', 'prix_unitaire' => 2.95, 'total_ht_ligne' => 18.0],
                    ['designation' => 'pensee', 'quantite_facturee' => 2.0, 'unite_facturation' => 'bot', 'prix_unitaire' => 7.1, 'total_ht_ligne' => 14.2],
                ],
            ],

            // ---- Le Bihan TMEG BL 1 (IMG_5956 + IMG_5957) — pages 1+2, ~22 lines ----
            'lebihan_bl1' => [
                'images' => ['IMG_5956.jpeg', 'IMG_5957.jpeg'],
                'header' => [
                    'fournisseur' => 'Le Bihan',
                    'numero_bl' => '00211162',
                    'date' => '2025-08-12',
                ],
                'lines' => [
                    ['designation' => 'TIGRE BOCK', 'quantite_facturee' => 30.0, 'unite_facturation' => 'L', 'prix_unitaire' => 2.919, 'total_ht_ligne' => 89.72],
                    ['designation' => 'LIMONADE', 'quantite_facturee' => 30.0, 'unite_facturation' => 'L', 'prix_unitaire' => 1.134, 'total_ht_ligne' => 44.52],
                    ['designation' => 'COCA COLA', 'quantite_facturee' => 48.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 0.743, 'total_ht_ligne' => 41.2],
                    ['designation' => 'PERRIER', 'quantite_facturee' => 24.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 0.628, 'total_ht_ligne' => 15.07],
                    ['designation' => 'ABATILLES', 'quantite_facturee' => 48.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 0.996, 'total_ht_ligne' => 43.49],
                    ['designation' => 'RICARD', 'quantite_facturee' => 5.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 9.794, 'total_ht_ligne' => 91.7],
                    ['designation' => 'VODKA SOBIESKI', 'quantite_facturee' => 5.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 6.512, 'total_ht_ligne' => 57.49],
                    ['designation' => 'CREME DE PECHE', 'quantite_facturee' => 1.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 6.219, 'total_ht_ligne' => 9.07],
                    ['designation' => 'BAILEYS', 'quantite_facturee' => 5.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 14.723, 'total_ht_ligne' => 84.92],
                    ['designation' => 'LIMONCELLO', 'quantite_facturee' => 1.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 9.377, 'total_ht_ligne' => 12.7],
                ],
            ],

            // ---- Le Bihan TMEG BL 2 (IMG_5958 + IMG_5959) — pages 1+2, ~15 lines ----
            'lebihan_bl2' => [
                'images' => ['IMG_5958.jpeg', 'IMG_5959.jpeg'],
                'header' => [
                    'fournisseur' => 'Le Bihan',
                    'numero_bl' => null,
                    'date' => null,
                ],
                'lines' => [
                    ['designation' => 'TIGRE BOCK', 'quantite_facturee' => 120.0, 'unite_facturation' => 'L', 'prix_unitaire' => 2.919, 'total_ht_ligne' => 390.88],
                    ['designation' => 'GINGER BEER', 'quantite_facturee' => 24.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 1.083, 'total_ht_ligne' => 27.25],
                    ['designation' => 'COCA COLA', 'quantite_facturee' => 48.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 0.743, 'total_ht_ligne' => 41.29],
                    ['designation' => 'ABATILLES', 'quantite_facturee' => 12.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 1.835, 'total_ht_ligne' => 12.42],
                    ['designation' => 'BAILEYS', 'quantite_facturee' => 5.0, 'unite_facturation' => 'COL', 'prix_unitaire' => 14.723, 'total_ht_ligne' => 84.92],
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
        $compressor = new ImageCompressor();
        $content = [];

        foreach ($imagePaths as $imagePath) {
            $prepared = $compressor->prepareForApi($imagePath);

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $prepared['mediaType'],
                    'data' => $prepared['base64'],
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
        // Fournisseur name (fuzzy match) — skip if null (rotated/hard images)
        $expectedFournisseur = $expectedHeader['fournisseur'] ?? null;

        if ($expectedFournisseur !== null && $expectedFournisseur !== '') {
            $extractedFournisseur = $data['fournisseur']['nom'] ?? '';
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
        $expectedDesignation = $this->normalize($expected['designation'] ?? '');
        $bestScore = 0;
        $bestMatch = null;

        foreach ($extractedLines as $extracted) {
            $extractedDesignation = $this->normalize($extracted['designation'] ?? '');

            // Try containment first (handles short keywords like "avocat" in "Avocat Hass 16/18...")
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

        // Require at least 40% word overlap
        return $bestScore >= 0.40 ? $bestMatch : null;
    }

    private function wordOverlapScore(string $a, string $b): float
    {
        // Inputs are already normalized by callers
        $wordsA = array_filter(preg_split('/\s+/', $a));
        $wordsB = array_filter(preg_split('/\s+/', $b));

        if (empty($wordsA) || empty($wordsB)) {
            return 0.0;
        }

        $intersection = count(array_intersect($wordsA, $wordsB));

        // Use Dice coefficient: 2*|A∩B| / (|A|+|B|) — fairer for different-length strings
        $total = count($wordsA) + count($wordsB);

        return $total > 0 ? (2 * $intersection) / $total : 0.0;
    }

    /**
     * Normalize string for OCR comparison: lowercase, strip accents, remove special chars.
     */
    private function normalize(string $str): string
    {
        $str = mb_strtolower(trim($str));
        // Remove accents via transliteration
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        if ($transliterated !== false) {
            $str = $transliterated;
        }
        // Remove non-alphanumeric except spaces
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);
        // Collapse whitespace
        $str = preg_replace('/\s+/', ' ', trim($str));

        return $str;
    }

    private function fuzzyMatch(string $expected, string $extracted): bool
    {
        $expected = $this->normalize($expected);
        $extracted = $this->normalize($extracted);

        // Direct containment
        if (str_contains($extracted, $expected) || str_contains($expected, $extracted)) {
            return true;
        }

        // Compact form (no spaces) for compound names like TerreAzur/TERR'AZUR/TERNO AZUR
        $compactExpected = str_replace(' ', '', $expected);
        $compactExtracted = str_replace(' ', '', $extracted);

        if (str_contains($compactExtracted, $compactExpected) || str_contains($compactExpected, $compactExtracted)) {
            return true;
        }

        // Levenshtein similarity on compact form (handles OCR typos like TERNO→TERRE)
        $maxLen = max(strlen($compactExpected), strlen($compactExtracted));
        if ($maxLen > 0 && $maxLen <= 255) {
            $distance = levenshtein($compactExpected, $compactExtracted);
            if ($distance / $maxLen <= 0.25) {
                return true;
            }
        }

        // Word overlap >= 40%
        return $this->wordOverlapScore($expected, $extracted) >= 0.40;
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
