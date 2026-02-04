<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\DTO\Import\ColumnMappingConfig;
use App\Entity\Unite;
use App\Repository\UniteRepository;
use Psr\Log\LoggerInterface;

class ColumnMapper
{
    /**
     * Mapping of common unit strings to database codes.
     * Database codes are: kg, g, L, cL, mL, p, bq, bt, ct, lot
     */
    private const UNITE_MAPPING = [
        // Kilogramme (code: kg)
        'kg' => 'kg',
        'kilo' => 'kg',
        'kilogramme' => 'kg',
        'kilogrammes' => 'kg',

        // Gramme (code: g)
        'g' => 'g',
        'gr' => 'g',
        'gramme' => 'g',
        'grammes' => 'g',

        // Litre (code: L)
        'l' => 'L',
        'litre' => 'L',
        'litres' => 'L',
        'lt' => 'L',

        // Centilitre (code: cL)
        'cl' => 'cL',
        'centilitre' => 'cL',
        'centilitres' => 'cL',

        // Millilitre (code: mL)
        'ml' => 'mL',
        'millilitre' => 'mL',
        'millilitres' => 'mL',

        // Pièce (code: p)
        'p' => 'p',
        'pc' => 'p',
        'pce' => 'p',
        'piece' => 'p',
        'pièce' => 'p',
        'pieces' => 'p',
        'pièces' => 'p',
        'u' => 'p',
        'unite' => 'p',
        'unité' => 'p',
        'unites' => 'p',
        'unités' => 'p',

        // Carton (code: ct)
        'ct' => 'ct',
        'crt' => 'ct',
        'carton' => 'ct',
        'cartons' => 'ct',

        // Bouteille (code: bt)
        'bt' => 'bt',
        'bte' => 'bt',
        'btl' => 'bt',
        'bouteille' => 'bt',
        'bouteilles' => 'bt',

        // Barquette (code: bq)
        'bq' => 'bq',
        'barquette' => 'bq',
        'barquettes' => 'bq',

        // Lot (code: lot)
        'lot' => 'lot',
        'lots' => 'lot',
    ];

    /** @var array<string, Unite>|null */
    private ?array $uniteCache = null;

    public function __construct(
        private readonly UniteRepository $uniteRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Apply column mapping to extract data from a row.
     *
     * @return array{
     *     code_fournisseur: ?string,
     *     designation: ?string,
     *     unite: ?string,
     *     prix: ?string,
     *     conditionnement: ?string,
     *     date_debut: ?string,
     *     date_fin: ?string
     * }
     */
    public function mapRow(array $row, ColumnMappingConfig $config): array
    {
        $result = [
            'code_fournisseur' => null,
            'designation' => null,
            'unite' => null,
            'prix' => null,
            'conditionnement' => null,
            'date_debut' => null,
            'date_fin' => null,
        ];

        foreach ($config->mapping as $columnIndex => $fieldName) {
            if ($fieldName === ColumnMappingConfig::FIELD_IGNORE || $fieldName === null) {
                continue;
            }

            // Ensure column index is an integer for array access
            $colIdx = (int) $columnIndex;
            $value = $row[$colIdx] ?? null;
            if ($value !== null && \array_key_exists($fieldName, $result)) {
                $result[$fieldName] = $this->normalizeValue($fieldName, $value);
            }
        }

        // Apply defaults
        if ($result['unite'] === null && $config->defaultUnite !== null) {
            $result['unite'] = $config->defaultUnite;
        }

        if ($result['date_debut'] === null && $config->defaultDateDebut !== null) {
            $result['date_debut'] = $config->defaultDateDebut->format('Y-m-d');
        }

        return $result;
    }

    private function normalizeValue(string $field, string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($field) {
            ColumnMappingConfig::FIELD_PRIX => $this->normalizePrice($value),
            ColumnMappingConfig::FIELD_UNITE => $this->normalizeUnite($value),
            ColumnMappingConfig::FIELD_CONDITIONNEMENT => $this->normalizeQuantity($value),
            ColumnMappingConfig::FIELD_DATE_DEBUT,
            ColumnMappingConfig::FIELD_DATE_FIN => $this->normalizeDate($value),
            default => $value,
        };
    }

    /**
     * Normalize price: handle comma as decimal separator, remove spaces, currency symbols.
     */
    public function normalizePrice(string $value): ?string
    {
        // Remove currency symbols, currency codes and spaces
        $value = preg_replace('/[€$£\s]/', '', $value);
        $value = preg_replace('/EUR|USD|GBP/i', '', $value);

        if ($value === null || $value === '') {
            return null;
        }

        // Handle French format: 1 234,56 or 1234,56
        // First, remove thousands separator (space or dot if comma is decimal)
        if (str_contains($value, ',')) {
            // Comma is decimal separator
            $value = str_replace(' ', '', $value);
            // If there's a dot before comma, it's a thousands separator
            if (preg_match('/^\d+\.\d{3},\d+$/', $value)) {
                $value = str_replace('.', '', $value);
            }
            $value = str_replace(',', '.', $value);
        } else {
            // Dot is decimal separator or there's no decimal
            $value = str_replace(' ', '', $value);
        }

        // Validate it's a number
        if (!is_numeric($value)) {
            $this->logger->warning('Invalid price value', ['value' => $value]);

            return null;
        }

        // Format to 4 decimal places
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * Normalize quantity: handle comma as decimal separator.
     */
    public function normalizeQuantity(string $value): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], $value);

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    /**
     * Normalize unit string to standard code.
     */
    public function normalizeUnite(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        // Direct match in mapping
        if (isset(self::UNITE_MAPPING[$normalized])) {
            return self::UNITE_MAPPING[$normalized];
        }

        // Check if it's already a valid unit code (exact match)
        $trimmedValue = trim($value);
        $units = $this->getUnitesCache();

        if (isset($units[$trimmedValue])) {
            return $trimmedValue;
        }

        // Try lowercase version
        if (isset($units[$normalized])) {
            return $normalized;
        }

        // Return as-is, will be validated later
        return $trimmedValue;
    }

    /**
     * Normalize date to Y-m-d format.
     */
    public function normalizeDate(string $value): ?string
    {
        // Try various date formats
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'd.m.Y',
            'Y/m/d',
            'd/m/y',
            'd-m-y',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        $this->logger->warning('Could not parse date', ['value' => $value]);

        return null;
    }

    /**
     * Resolve a unit string to a Unite entity.
     */
    public function resolveUnite(string $code): ?Unite
    {
        $units = $this->getUnitesCache();

        return $units[$code] ?? null;
    }

    /**
     * @return array<string, Unite>
     */
    private function getUnitesCache(): array
    {
        if ($this->uniteCache === null) {
            $this->uniteCache = [];
            $units = $this->uniteRepository->findAll();
            foreach ($units as $unit) {
                $this->uniteCache[$unit->getCode()] = $unit;
            }
        }

        return $this->uniteCache;
    }

    /**
     * Validate mapped data for a single row.
     *
     * Returns errors (blocking) and warnings (non-blocking).
     * Import can proceed with warnings but not with errors.
     *
     * @return array{valid: bool, errors: array<array{field: string, message: string}>, warnings: array<array{field: string, message: string}>}
     */
    public function validateMappedRow(array $mappedData): array
    {
        $errors = [];
        $warnings = [];

        // We need at least a code OR a designation to identify the product
        $hasCode = !empty($mappedData['code_fournisseur']);
        $hasDesignation = !empty($mappedData['designation']);

        if (!$hasCode && !$hasDesignation) {
            $errors[] = ['field' => 'code_fournisseur', 'message' => 'Code fournisseur ou désignation requis'];
        }

        // Missing code is a warning if we have designation
        if (!$hasCode && $hasDesignation) {
            $warnings[] = ['field' => 'code_fournisseur', 'message' => 'Code fournisseur manquant (sera généré)'];
        }

        // Missing designation is a warning if we have code
        if ($hasCode && !$hasDesignation) {
            $warnings[] = ['field' => 'designation', 'message' => 'Désignation manquante'];
        }

        // Prix: warning if missing or invalid, not blocking
        if (empty($mappedData['prix'])) {
            $warnings[] = ['field' => 'prix', 'message' => 'Prix manquant (sera à renseigner)'];
        } elseif (!is_numeric($mappedData['prix']) || (float) $mappedData['prix'] < 0) {
            $warnings[] = ['field' => 'prix', 'message' => 'Prix invalide (sera ignoré)'];
        }

        // Unite: warning if not recognized, will use default
        if (!empty($mappedData['unite'])) {
            $unit = $this->resolveUnite($mappedData['unite']);
            if ($unit === null) {
                $warnings[] = ['field' => 'unite', 'message' => sprintf('Unité "%s" non reconnue (unité par défaut utilisée)', $mappedData['unite'])];
            }
        }

        // Conditionnement: warning if invalid
        if (!empty($mappedData['conditionnement'])) {
            if (!is_numeric($mappedData['conditionnement']) || (float) $mappedData['conditionnement'] <= 0) {
                $warnings[] = ['field' => 'conditionnement', 'message' => 'Conditionnement invalide (sera ignoré)'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
