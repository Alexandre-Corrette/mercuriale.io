<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutFacture: string
{
    case RECUE = 'RECUE';
    case ACCEPTEE = 'ACCEPTEE';
    case REFUSEE = 'REFUSEE';
    case PAYEE = 'PAYEE';
    case RAPPROCHEE = 'RAPPROCHEE';

    public function label(): string
    {
        return match ($this) {
            self::RECUE => 'Reçue',
            self::ACCEPTEE => 'Acceptée',
            self::REFUSEE => 'Refusée',
            self::PAYEE => 'Payée',
            self::RAPPROCHEE => 'Rapprochée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RECUE => 'info',
            self::ACCEPTEE => 'success',
            self::REFUSEE => 'danger',
            self::PAYEE => 'gold',
            self::RAPPROCHEE => 'secondary',
        };
    }
}
