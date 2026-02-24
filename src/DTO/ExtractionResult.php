<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\LigneBonLivraison;

final readonly class ExtractionResult
{
    /**
     * @param LigneBonLivraison[] $lignes              Lignes extraites et mappées
     * @param string[]            $warnings            Messages d'avertissement
     * @param string              $confiance           Niveau de confiance (haute|moyenne|basse)
     * @param string[]            $produitsNonMatches  Codes produits non trouvés en base
     * @param float               $tempsExtraction     Durée en secondes
     * @param array               $donneesBrutes       Données brutes de l'extraction
     */
    public function __construct(
        public bool $success,
        public array $lignes = [],
        public array $warnings = [],
        public string $confiance = 'basse',
        public array $produitsNonMatches = [],
        public float $tempsExtraction = 0.0,
        public array $donneesBrutes = [],
    ) {
    }

    public function getNombreLignes(): int
    {
        return count($this->lignes);
    }

    public function getNombreProduitsNonMatches(): int
    {
        return count($this->produitsNonMatches);
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }
}
