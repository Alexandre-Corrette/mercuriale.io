<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class ImportResult
{
    public function __construct(
        public bool $success,
        public int $totalProcessed,
        public int $productsCreated,
        public int $productsUpdated,
        public int $mercurialesCreated,
        public int $mercurialesUpdated,
        public int $skipped,
        public int $failed,
        /** @var ImportError[] */
        public array $errors = [],
        public float $executionTime = 0.0,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success && $this->failed === 0;
    }

    public function isPartialSuccess(): bool
    {
        return $this->success && $this->failed > 0 && ($this->productsCreated + $this->productsUpdated) > 0;
    }

    public function getSummary(): string
    {
        $parts = [];

        if ($this->productsCreated > 0) {
            $parts[] = sprintf('%d produit(s) créé(s)', $this->productsCreated);
        }
        if ($this->productsUpdated > 0) {
            $parts[] = sprintf('%d produit(s) mis à jour', $this->productsUpdated);
        }
        if ($this->mercurialesCreated > 0) {
            $parts[] = sprintf('%d prix créé(s)', $this->mercurialesCreated);
        }
        if ($this->mercurialesUpdated > 0) {
            $parts[] = sprintf('%d prix mis à jour', $this->mercurialesUpdated);
        }
        if ($this->skipped > 0) {
            $parts[] = sprintf('%d ligne(s) ignorée(s)', $this->skipped);
        }
        if ($this->failed > 0) {
            $parts[] = sprintf('%d erreur(s)', $this->failed);
        }

        return implode(', ', $parts) ?: 'Aucune modification';
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'totalProcessed' => $this->totalProcessed,
            'productsCreated' => $this->productsCreated,
            'productsUpdated' => $this->productsUpdated,
            'mercurialesCreated' => $this->mercurialesCreated,
            'mercurialesUpdated' => $this->mercurialesUpdated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => array_map(fn (ImportError $e) => $e->toArray(), $this->errors),
            'executionTime' => $this->executionTime,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'],
            totalProcessed: $data['totalProcessed'],
            productsCreated: $data['productsCreated'],
            productsUpdated: $data['productsUpdated'],
            mercurialesCreated: $data['mercurialesCreated'],
            mercurialesUpdated: $data['mercurialesUpdated'],
            skipped: $data['skipped'],
            failed: $data['failed'],
            errors: array_map(fn (array $e) => ImportError::fromArray($e), $data['errors'] ?? []),
            executionTime: $data['executionTime'] ?? 0.0,
        );
    }

    public static function failure(array $errors): self
    {
        return new self(
            success: false,
            totalProcessed: 0,
            productsCreated: 0,
            productsUpdated: 0,
            mercurialesCreated: 0,
            mercurialesUpdated: 0,
            skipped: 0,
            failed: \count($errors),
            errors: $errors,
        );
    }
}
