<?php

declare(strict_types=1);

namespace App\Enum;

enum StatutEmail: string
{
    case SENT = 'sent';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Envoyé',
            self::FAILED => 'Échec',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SENT => 'success',
            self::FAILED => 'danger',
        };
    }
}