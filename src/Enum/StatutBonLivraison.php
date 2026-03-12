<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutBonLivraison: string
{
    case BROUILLON = 'BROUILLON';
    case VALIDE = 'VALIDE';
    case ANOMALIE = 'ANOMALIE';
    case DOUBLON = 'DOUBLON';
    case ARCHIVE = 'ARCHIVE';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::VALIDE => 'Validé',
            self::ANOMALIE => 'Anomalie',
            self::DOUBLON => 'Doublon',
            self::ARCHIVE => 'Archivé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BROUILLON => 'secondary',
            self::VALIDE => 'success',
            self::ANOMALIE => 'danger',
            self::DOUBLON => 'warning',
            self::ARCHIVE => 'dark',
        };
    }
}
