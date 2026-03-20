<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutBonLivraison: string
{
    case BROUILLON = 'BROUILLON';
    case EN_COURS_OCR = 'EN_COURS_OCR';
    case ECHEC_OCR = 'ECHEC_OCR';
    case VALIDE = 'VALIDE';
    case ANOMALIE = 'ANOMALIE';
    case DOUBLON = 'DOUBLON';
    case ARCHIVE = 'ARCHIVE';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::EN_COURS_OCR => 'OCR en cours',
            self::ECHEC_OCR => 'Échec OCR',
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
            self::EN_COURS_OCR => 'info',
            self::ECHEC_OCR => 'danger',
            self::VALIDE => 'success',
            self::ANOMALIE => 'danger',
            self::DOUBLON => 'warning',
            self::ARCHIVE => 'dark',
        };
    }
}
