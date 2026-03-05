<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\ProduitFournisseur;
use App\Enum\MatchConfidence;

final readonly class MatchResult
{
    public function __construct(
        public ?ProduitFournisseur $produitFournisseur,
        public MatchConfidence $confidence,
        public string $matchedBy,
        public ?float $similarityScore = null,
    ) {
    }

    public function isMatched(): bool
    {
        return $this->produitFournisseur !== null;
    }
}
