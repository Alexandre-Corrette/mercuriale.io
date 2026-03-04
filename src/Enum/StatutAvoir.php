<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutAvoir: string
{
    case DEMANDE = 'DEMANDE';
    case RECU = 'RECU';
    case IMPUTE = 'IMPUTE';
    case REFUSE = 'REFUSE';
    case ANNULE = 'ANNULE';

    public function label(): string
    {
        return match ($this) {
            self::DEMANDE => 'Demandé',
            self::RECU => 'Reçu',
            self::IMPUTE => 'Imputé',
            self::REFUSE => 'Refusé',
            self::ANNULE => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DEMANDE => 'warning',
            self::RECU => 'info',
            self::IMPUTE => 'success',
            self::REFUSE => 'danger',
            self::ANNULE => 'secondary',
        };
    }
}
