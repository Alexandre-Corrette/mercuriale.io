<?php

declare(strict_types=1);

namespace App\Enum;

enum TypeAlerte: string
{
    case ECART_QUANTITE = 'ECART_QUANTITE';
    case ECART_PRIX = 'ECART_PRIX';
    case PRODUIT_INCONNU = 'PRODUIT_INCONNU';
    case PRIX_MANQUANT = 'PRIX_MANQUANT';

    public function label(): string
    {
        return match ($this) {
            self::ECART_QUANTITE => 'Écart de quantité',
            self::ECART_PRIX => 'Écart de prix',
            self::PRODUIT_INCONNU => 'Produit inconnu',
            self::PRIX_MANQUANT => 'Prix manquant',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ECART_QUANTITE => 'warning',
            self::ECART_PRIX => 'danger',
            self::PRODUIT_INCONNU => 'info',
            self::PRIX_MANQUANT => 'secondary',
        };
    }
}
