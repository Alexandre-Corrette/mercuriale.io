<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutImport: string
{
    case PENDING = 'PENDING';
    case MAPPING = 'MAPPING';
    case PREVIEWED = 'PREVIEWED';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::MAPPING => 'Mapping en cours',
            self::PREVIEWED => 'Prévisualisé',
            self::COMPLETED => 'Terminé',
            self::FAILED => 'Échoué',
            self::EXPIRED => 'Expiré',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'secondary',
            self::MAPPING => 'info',
            self::PREVIEWED => 'warning',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::EXPIRED => 'dark',
        };
    }
}
