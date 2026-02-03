<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeUnite: string
{
    case POIDS = 'poids';
    case VOLUME = 'volume';
    case QUANTITE = 'quantite';

    public function label(): string
    {
        return match ($this) {
            self::POIDS => 'Poids',
            self::VOLUME => 'Volume',
            self::QUANTITE => 'Quantité',
        };
    }
}
