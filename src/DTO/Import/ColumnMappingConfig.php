<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class ColumnMappingConfig
{
    public const FIELD_CODE = 'code_fournisseur';
    public const FIELD_DESIGNATION = 'designation';
    public const FIELD_UNITE = 'unite';
    public const FIELD_PRIX = 'prix';
    public const FIELD_CONDITIONNEMENT = 'conditionnement';
    public const FIELD_DATE_DEBUT = 'date_debut';
    public const FIELD_DATE_FIN = 'date_fin';
    public const FIELD_IGNORE = '_ignore';

    public const REQUIRED_FIELDS = [
        self::FIELD_CODE,
        self::FIELD_DESIGNATION,
        self::FIELD_PRIX,
    ];

    public const OPTIONAL_FIELDS = [
        self::FIELD_UNITE,
        self::FIELD_CONDITIONNEMENT,
        self::FIELD_DATE_DEBUT,
        self::FIELD_DATE_FIN,
    ];

    public const FIELD_LABELS = [
        self::FIELD_CODE => 'Code produit fournisseur',
        self::FIELD_DESIGNATION => 'Désignation',
        self::FIELD_UNITE => 'Unité',
        self::FIELD_PRIX => 'Prix unitaire HT',
        self::FIELD_CONDITIONNEMENT => 'Conditionnement',
        self::FIELD_DATE_DEBUT => 'Date de début',
        self::FIELD_DATE_FIN => 'Date de fin',
        self::FIELD_IGNORE => 'Ignorer cette colonne',
    ];

    public function __construct(
        /** @var array<string, string|null> Column index => field name mapping */
        public array $mapping,
        public bool $hasHeaderRow = true,
        public ?string $defaultUnite = null,
        public ?\DateTimeImmutable $defaultDateDebut = null,
    ) {}

    public function getMappedField(int $columnIndex): ?string
    {
        return $this->mapping[$columnIndex] ?? null;
    }

    public function getColumnForField(string $field): ?int
    {
        $flipped = array_flip($this->mapping);

        return $flipped[$field] ?? null;
    }

    public function hasRequiredFields(): bool
    {
        $mappedFields = array_values($this->mapping);

        foreach (self::REQUIRED_FIELDS as $required) {
            if (!\in_array($required, $mappedFields, true)) {
                return false;
            }
        }

        return true;
    }

    public function getMissingRequiredFields(): array
    {
        $mappedFields = array_values($this->mapping);
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $required) {
            if (!\in_array($required, $mappedFields, true)) {
                $missing[] = self::FIELD_LABELS[$required];
            }
        }

        return $missing;
    }

    public function toArray(): array
    {
        return [
            'mapping' => $this->mapping,
            'hasHeaderRow' => $this->hasHeaderRow,
            'defaultUnite' => $this->defaultUnite,
            'defaultDateDebut' => $this->defaultDateDebut?->format('Y-m-d'),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            mapping: $data['mapping'] ?? [],
            hasHeaderRow: $data['hasHeaderRow'] ?? true,
            defaultUnite: $data['defaultUnite'] ?? null,
            defaultDateDebut: isset($data['defaultDateDebut'])
                ? new \DateTimeImmutable($data['defaultDateDebut'])
                : null,
        );
    }

    public static function getAllFields(): array
    {
        return array_merge(self::REQUIRED_FIELDS, self::OPTIONAL_FIELDS, [self::FIELD_IGNORE]);
    }
}
