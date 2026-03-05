<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a manual invoice file upload to trigger async OCR extraction.
 */
final readonly class ProcessFactureOcrMessage
{
    public function __construct(
        public string $factureId,
    ) {
    }
}
