<?php

declare(strict_types=1);

namespace App\Enum;

enum MotifAvoir: string
{
    case ECART_PRIX = 'ECART_PRIX';
    case ECART_QUANTITE = 'ECART_QUANTITE';
    case RETOUR_MARCHANDISE = 'RETOUR_MARCHANDISE';
    case GESTE_COMMERCIAL = 'GESTE_COMMERCIAL';
    case ERREUR_FACTURATION = 'ERREUR_FACTURATION';

    public function label(): string
    {
        return match ($this) {
            self::ECART_PRIX => 'Écart de prix',
            self::ECART_QUANTITE => 'Écart de quantité',
            self::RETOUR_MARCHANDISE => 'Retour marchandise',
            self::GESTE_COMMERCIAL => 'Geste commercial',
            self::ERREUR_FACTURATION => 'Erreur de facturation',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ECART_PRIX => 'danger',
            self::ECART_QUANTITE => 'warning',
            self::RETOUR_MARCHANDISE => 'coral',
            self::GESTE_COMMERCIAL => 'gold',
            self::ERREUR_FACTURATION => 'secondary',
        };
    }
}
