<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class ImportPreviewLine
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';
    public const ACTION_ERROR = 'error';

    public function __construct(
        public int $row,
        public string $action,
        public ?string $codeFournisseur,
        public ?string $designation,
        public ?string $unite,
        public ?string $prix,
        public ?int $existingProductId = null,
        public ?int $existingMercurialeId = null,
        /** @var ImportError[] */
        public array $errors = [],
        /** @var ImportWarning[] */
        public array $warnings = [],
        public array $rawData = [],
    ) {}

    public function hasErrors(): bool
    {
        return \count($this->errors) > 0;
    }

    public function hasWarnings(): bool
    {
        return \count($this->warnings) > 0;
    }

    public function isCreation(): bool
    {
        return $this->action === self::ACTION_CREATE;
    }

    public function isUpdate(): bool
    {
        return $this->action === self::ACTION_UPDATE;
    }

    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'action' => $this->action,
            'codeFournisseur' => $this->codeFournisseur,
            'designation' => $this->designation,
            'unite' => $this->unite,
            'prix' => $this->prix,
            'existingProductId' => $this->existingProductId,
            'existingMercurialeId' => $this->existingMercurialeId,
            'errors' => array_map(fn (ImportError $e) => $e->toArray(), $this->errors),
            'warnings' => array_map(fn (ImportWarning $w) => $w->toArray(), $this->warnings),
            'rawData' => $this->rawData,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            row: $data['row'],
            action: $data['action'],
            codeFournisseur: $data['codeFournisseur'] ?? null,
            designation: $data['designation'] ?? null,
            unite: $data['unite'] ?? null,
            prix: $data['prix'] ?? null,
            existingProductId: $data['existingProductId'] ?? null,
            existingMercurialeId: $data['existingMercurialeId'] ?? null,
            errors: array_map(fn (array $e) => ImportError::fromArray($e), $data['errors'] ?? []),
            warnings: array_map(fn (array $w) => ImportWarning::fromArray($w), $data['warnings'] ?? []),
            rawData: $data['rawData'] ?? [],
        );
    }
}
