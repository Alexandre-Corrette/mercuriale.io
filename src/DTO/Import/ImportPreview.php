<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class ImportPreview
{
    public function __construct(
        public int $totalRows,
        public int $validRows,
        public int $errorRows,
        public int $createCount,
        public int $updateCount,
        public int $skipCount,
        /** @var ImportPreviewLine[] */
        public array $lines,
        /** @var ImportError[] */
        public array $globalErrors = [],
        /** @var ImportWarning[] */
        public array $globalWarnings = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errorRows > 0 || \count($this->globalErrors) > 0;
    }

    public function hasWarnings(): bool
    {
        if (\count($this->globalWarnings) > 0) {
            return true;
        }

        foreach ($this->lines as $line) {
            if ($line->hasWarnings()) {
                return true;
            }
        }

        return false;
    }

    public function canProceed(): bool
    {
        return !$this->hasErrors() && $this->validRows > 0;
    }

    public function getSuccessRate(): float
    {
        if ($this->totalRows === 0) {
            return 0.0;
        }

        return round(($this->validRows / $this->totalRows) * 100, 1);
    }

    public function toArray(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'validRows' => $this->validRows,
            'errorRows' => $this->errorRows,
            'createCount' => $this->createCount,
            'updateCount' => $this->updateCount,
            'skipCount' => $this->skipCount,
            'lines' => array_map(fn (ImportPreviewLine $l) => $l->toArray(), $this->lines),
            'globalErrors' => array_map(fn (ImportError $e) => $e->toArray(), $this->globalErrors),
            'globalWarnings' => array_map(fn (ImportWarning $w) => $w->toArray(), $this->globalWarnings),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            totalRows: $data['totalRows'],
            validRows: $data['validRows'],
            errorRows: $data['errorRows'],
            createCount: $data['createCount'],
            updateCount: $data['updateCount'],
            skipCount: $data['skipCount'],
            lines: array_map(fn (array $l) => ImportPreviewLine::fromArray($l), $data['lines'] ?? []),
            globalErrors: array_map(fn (array $e) => ImportError::fromArray($e), $data['globalErrors'] ?? []),
            globalWarnings: array_map(fn (array $w) => ImportWarning::fromArray($w), $data['globalWarnings'] ?? []),
        );
    }
}
