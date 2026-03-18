<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class RowValidationResult
{
    /**
     * @param array<string, mixed> $mappedData  The mapped row data (validated)
     * @param ImportError[]         $errors      Validation errors (empty if valid)
     */
    public function __construct(
        public int $rowNumber,
        public array $mappedData,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
