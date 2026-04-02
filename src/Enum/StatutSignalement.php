<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutSignalement: string
{
    case SIGNALE = 'SIGNALE';
    case RECLAME = 'RECLAME';
    case RESOLU_AVOIR = 'RESOLU_AVOIR';
    case RESOLU_REMPLACEMENT = 'RESOLU_REMPLACEMENT';
    case REFUSE = 'REFUSE';
    case CLASSE = 'CLASSE';

    public function label(): string
    {
        return match ($this) {
            self::SIGNALE => 'Signalé',
            self::RECLAME => 'Réclamé',
            self::RESOLU_AVOIR => 'Résolu (avoir)',
            self::RESOLU_REMPLACEMENT => 'Résolu (remplacement)',
            self::REFUSE => 'Refusé',
            self::CLASSE => 'Classé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SIGNALE => 'warning',
            self::RECLAME => 'info',
            self::RESOLU_AVOIR => 'success',
            self::RESOLU_REMPLACEMENT => 'success',
            self::REFUSE => 'danger',
            self::CLASSE => 'secondary',
        };
    }
}
