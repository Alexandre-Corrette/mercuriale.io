<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutControle: string
{
    case OK = 'OK';
    case ECART_QTE = 'ECART_QTE';
    case ECART_PRIX = 'ECART_PRIX';
    case ECART_MULTIPLE = 'ECART_MULTIPLE';
    case NON_CONTROLE = 'NON_CONTROLE';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::ECART_QTE => 'Écart quantité',
            self::ECART_PRIX => 'Écart prix',
            self::ECART_MULTIPLE => 'Écarts multiples',
            self::NON_CONTROLE => 'Non contrôlé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::ECART_QTE => 'warning',
            self::ECART_PRIX => 'warning',
            self::ECART_MULTIPLE => 'danger',
            self::NON_CONTROLE => 'secondary',
        };
    }
}
