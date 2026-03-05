<?php

declare(strict_types=1);

namespace App\Enum;

enum MatchConfidence: string
{
    case EXACT = 'EXACT';
    case FUZZY = 'FUZZY';
    case NONE = 'NONE';

    public function label(): string
    {
        return match ($this) {
            self::EXACT => 'Match exact',
            self::FUZZY => 'Match fuzzy',
            self::NONE => 'Non trouvé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EXACT => 'success',
            self::FUZZY => 'warning',
            self::NONE => 'danger',
        };
    }
}
