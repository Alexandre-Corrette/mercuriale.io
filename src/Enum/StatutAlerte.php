<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutAlerte: string
{
    case NOUVELLE = 'NOUVELLE';
    case VUE = 'VUE';
    case ACCEPTEE = 'ACCEPTEE';
    case REFUSEE = 'REFUSEE';

    public function label(): string
    {
        return match ($this) {
            self::NOUVELLE => 'Nouvelle',
            self::VUE => 'Vue',
            self::ACCEPTEE => 'Acceptée',
            self::REFUSEE => 'Refusée',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NOUVELLE => 'danger',
            self::VUE => 'warning',
            self::ACCEPTEE => 'success',
            self::REFUSEE => 'secondary',
        };
    }
}
