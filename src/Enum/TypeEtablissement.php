<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeEtablissement: string
{
    case RESTAURANT = 'restaurant';
    case BAR = 'bar';
    case HOTEL = 'hotel';
    case TRAITEUR = 'traiteur';
    case BRASSERIE = 'brasserie';

    public function label(): string
    {
        return match ($this) {
            self::RESTAURANT => 'Restaurant',
            self::BAR => 'Bar',
            self::HOTEL => 'Hôtel',
            self::TRAITEUR => 'Traiteur',
            self::BRASSERIE => 'Brasserie',
        };
    }

    /**
     * Mapping codes NAF (INSEE) vers type d'établissement.
     * Retourne null si le code NAF ne correspond à aucun type connu.
     */
    public static function fromCodeNaf(string $codeNaf): ?self
    {
        return match ($codeNaf) {
            '56.10A', '56.10B', '56.10C' => self::RESTAURANT,
            '56.21Z' => self::TRAITEUR,
            '56.30Z' => self::BAR,
            '55.10Z' => self::HOTEL,
            default => null,
        };
    }
}
