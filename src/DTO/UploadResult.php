<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\BonLivraison;

/**
 * DTO pour encapsuler les résultats d'un upload multiple.
 */
final readonly class UploadResult
{
    /**
     * @param array<int, BonLivraison> $successfulUploads Liste des BL créés avec succès
     * @param array<int, array{filename: string, error: string}> $failedUploads Liste des fichiers en erreur
     */
    public function __construct(
        private array $successfulUploads = [],
        private array $failedUploads = [],
    ) {
    }

    /**
     * @return array<int, BonLivraison>
     */
    public function getSuccessfulUploads(): array
    {
        return $this->successfulUploads;
    }

    /**
     * @return array<int, array{filename: string, error: string}>
     */
    public function getFailedUploads(): array
    {
        return $this->failedUploads;
    }

    public function getSuccessCount(): int
    {
        return count($this->successfulUploads);
    }

    public function getFailureCount(): int
    {
        return count($this->failedUploads);
    }

    public function getTotalCount(): int
    {
        return $this->getSuccessCount() + $this->getFailureCount();
    }

    public function hasFailures(): bool
    {
        return $this->getFailureCount() > 0;
    }

    public function hasSuccesses(): bool
    {
        return $this->getSuccessCount() > 0;
    }

    public function isFullSuccess(): bool
    {
        return !$this->hasFailures() && $this->hasSuccesses();
    }

    public function isFullFailure(): bool
    {
        return !$this->hasSuccesses() && $this->hasFailures();
    }

    /**
     * @return array<int, int|null>
     */
    public function getSuccessfulIds(): array
    {
        return array_map(
            fn (BonLivraison $bl) => $bl->getId(),
            $this->successfulUploads
        );
    }
}
