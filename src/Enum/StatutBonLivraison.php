<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutBonLivraison: string
{
    case BROUILLON = 'BROUILLON';
    case VALIDE = 'VALIDE';
    case ANOMALIE = 'ANOMALIE';
    case ARCHIVE = 'ARCHIVE';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDE => 'Validé',
            self::ANOMALIE => 'Anomalie',
            self::ARCHIVE => 'Archivé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::VALIDE => 'success',
            self::ANOMALIE => 'danger',
            self::ARCHIVE => 'dark',
        };
    }
}
