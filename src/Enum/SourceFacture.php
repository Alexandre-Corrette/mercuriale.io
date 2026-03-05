<?php

declare(strict_types=1);

namespace App\Enum;

enum SourceFacture: string
{
    case B2BROUTER = 'B2BROUTER';
    case UPLOAD_OCR = 'UPLOAD_OCR';

    public function label(): string
    {
        return match ($this) {
            self::B2BROUTER => 'B2Brouter',
            self::UPLOAD_OCR => 'Upload OCR',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::B2BROUTER => 'info',
            self::UPLOAD_OCR => 'warning',
        };
    }
}
