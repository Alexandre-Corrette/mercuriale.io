<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutFacture: string
{
    case BROUILLON = 'BROUILLON';
    case RECUE = 'RECUE';
    case ACCEPTEE = 'ACCEPTEE';
    case REFUSEE = 'REFUSEE';
    case PAYEE = 'PAYEE';
    case RAPPROCHEE = 'RAPPROCHEE';
    case CONTESTEE = 'CONTESTEE';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::RECUE => 'Reçue',
            self::ACCEPTEE => 'Acceptée',
            self::REFUSEE => 'Refusée',
            self::PAYEE => 'Payée',
            self::RAPPROCHEE => 'Rapprochée',
            self::CONTESTEE => 'Contestée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::RECUE => 'info',
            self::ACCEPTEE => 'success',
            self::REFUSEE => 'danger',
            self::PAYEE => 'gold',
            self::RAPPROCHEE => 'secondary',
            self::CONTESTEE => 'orange',
        };
    }
}
