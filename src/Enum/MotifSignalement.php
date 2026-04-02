<?php

declare(strict_types=1);

namespace App\Enum;

enum MotifSignalement: string
{
    case NON_CONFORME = 'NON_CONFORME';
    case ABIME = 'ABIME';
    case PERIME = 'PERIME';
    case MANQUANT = 'MANQUANT';
    case TEMPERATURE = 'TEMPERATURE';
    case AUTRE = 'AUTRE';

    public function label(): string
    {
        return match ($this) {
            self::NON_CONFORME => 'Non conforme',
            self::ABIME => 'Abîmé',
            self::PERIME => 'Périmé / DLC courte',
            self::MANQUANT => 'Manquant',
            self::TEMPERATURE => 'Rupture chaîne du froid',
            self::AUTRE => 'Autre',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NON_CONFORME => 'warning',
            self::ABIME => 'coral',
            self::PERIME => 'danger',
            self::MANQUANT => 'info',
            self::TEMPERATURE => 'danger',
            self::AUTRE => 'secondary',
        };
    }
}
