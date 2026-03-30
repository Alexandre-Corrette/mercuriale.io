<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\LigneBonLivraison;

final readonly class BonLivraisonMappingResult
{
    /**
     * @param LigneBonLivraison[]                                                    $lignes
     * @param array<array{code: ?string, designation: ?string, confidence: string}> $produitsNonMatches
     */
    public function __construct(
        public array $lignes = [],
        public array $produitsNonMatches = [],
    ) {
    }
}
